import { describe, expect, it } from "vitest";
import { validateDefinition } from "@/lib/workflows/definition";
import type { GraphDefinition, WorkflowNode } from "@/lib/workflows/types";

function node(id: string, type: WorkflowNode["type"], data: WorkflowNode["data"]): WorkflowNode {
  return { id, type, position: { x: 0, y: 0 }, data };
}

function edge(id: string, source: string, target: string, sourceHandle: string) {
  return { id, source, target, source_handle: sourceHandle, target_handle: "in" as const };
}

function publishable(): GraphDefinition {
  return {
    nodes: [
      node("start", "start", { label: "Start", description: "" }),
      node("approval", "approval", { label: "Manager approval", description: "", assignee_roles: ["manager"] }),
      node("revision", "revision", {
        label: "Send back",
        description: "",
        max_returns: 3,
        return_to_node_id: "approval",
      }),
      node("completed", "end", { label: "Completed", description: "", result: "completed" }),
      node("rejected", "end", { label: "Rejected", description: "", result: "rejected" }),
    ],
    edges: [
      edge("e1", "start", "approval", "out"),
      edge("e2", "approval", "completed", "approved"),
      edge("e3", "approval", "revision", "rejected"),
      edge("e4", "revision", "approval", "return"),
      edge("e5", "revision", "rejected", "exhausted"),
    ],
  };
}

describe("workflow definition validation", () => {
  it("accepts a revision that returns to an ancestor approval", () => {
    expect(validateDefinition(publishable(), { fields: [] })).toEqual([]);
  });

  it("rejects a return to start and an exhausted path that is not rejected", () => {
    const backToStart = publishable();
    backToStart.edges = backToStart.edges.map((item) => (item.source_handle === "return" ? { ...item, target: "start" } : item));
    backToStart.nodes[2].data.return_to_node_id = "start";

    expect(validateDefinition(backToStart, { fields: [] }).map((item) => item.code)).toContain("revision_target");

    const exhausted = publishable();
    exhausted.edges = exhausted.edges.map((item) => (item.source_handle === "exhausted" ? { ...item, target: "completed" } : item));

    expect(validateDefinition(exhausted, { fields: [] }).map((item) => item.code)).toContain("exhausted_result");
  });

  it("rejects an operator that does not match the field type", () => {
    const graph = publishable();
    graph.nodes.push(
      node("condition", "condition", {
        label: "High value",
        description: "",
        match: "all",
        rules: [{ id: "rule", field_id: "purchase_amount", operator: "is_true", value: null }],
      }),
    );

    const codes = validateDefinition(graph, {
      fields: [
        {
          id: "purchase_amount",
          type: "number",
          label: "Purchase amount",
          description: "",
          required: true,
          default_value: null,
          validation: {},
          options: [],
        },
      ],
    }).map((item) => item.code);

    expect(codes).toContain("operator_mismatch");
  });

  it("requires notification channels, recipients, and subject", () => {
    const graph: GraphDefinition = {
      nodes: [
        node("start", "start", { label: "Start", description: "" }),
        node("notify", "notification", {
          label: "Notify",
          description: "",
          channels: [],
          recipient_roles: [],
          include_submitter: false,
          include_task_participants: false,
          subject: "",
          body: "",
        }),
        node("done", "end", { label: "Done", description: "", result: "completed" }),
      ],
      edges: [
        edge("e1", "start", "notify", "out"),
        edge("e2", "notify", "done", "out"),
      ],
    };

    const codes = validateDefinition(graph, { fields: [] }).map((item) => item.code);

    expect(codes).toContain("notification_channels");
    expect(codes).toContain("notification_recipients");
    expect(codes).toContain("incomplete_config");
  });
});
