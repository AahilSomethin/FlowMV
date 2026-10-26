import { describe, expect, it } from "vitest";
import { validateDefinition } from "@/lib/workflows/definition";
import {
  addWorkflowNode,
  connectNodes,
  createEditorState,
  isDirty,
  markSaved,
  undo,
  updateNodeData,
} from "@/lib/workflows/editor-state";

describe("workflow editor state", () => {
  it("adds nodes, connects them, edits properties, and undoes the edit", () => {
    let state = createEditorState({ nodes: [], edges: [] }, { fields: [] }, 1);
    state = addWorkflowNode(state, "start", { x: 0, y: 0 }, "start");
    state = addWorkflowNode(state, "approval", { x: 40, y: 0 }, "approval");
    state = addWorkflowNode(state, "start", { x: 80, y: 0 }, "start-2");
    state = connectNodes(state, { source: "start", target: "approval", sourceHandle: "out", id: "edge" });
    state = updateNodeData(state, "approval", { label: "Finance approval", assignee_roles: ["manager"] });

    expect(state.nodes.filter((node) => node.type === "start")).toHaveLength(1);
    expect(state.edges).toEqual([
      { id: "edge", source: "start", target: "approval", source_handle: "out", target_handle: "in" },
    ]);
    expect(state.nodes.find((node) => node.id === "approval")?.data.label).toBe("Finance approval");
    expect(isDirty(state)).toBe(true);

    const undone = undo(state);

    expect(undone.nodes.find((node) => node.id === "approval")?.data.label).toBe("Approval");
  });

  it("clears the unsaved flag after a draft is saved and reloaded", () => {
    let state = createEditorState({ nodes: [], edges: [] }, { fields: [] }, 1);
    state = addWorkflowNode(state, "end", { x: 0, y: 0 }, "end");
    const saved = markSaved(state, { nodes: state.nodes, edges: state.edges }, { fields: state.fields }, 2);
    const reloaded = createEditorState({ nodes: saved.nodes, edges: saved.edges }, { fields: saved.fields }, saved.revision);

    expect(isDirty(saved)).toBe(false);
    expect(reloaded.revision).toBe(2);
    expect(isDirty(reloaded)).toBe(false);
    expect(validateDefinition({ nodes: reloaded.nodes, edges: reloaded.edges }, { fields: [] }).length).toBeGreaterThan(0);
  });

  it("does not connect a revision return to start", () => {
    let state = createEditorState({ nodes: [], edges: [] }, { fields: [] }, 1);
    state = addWorkflowNode(state, "start", { x: 0, y: 0 }, "start");
    state = addWorkflowNode(state, "revision", { x: 0, y: 40 }, "revision");
    state = connectNodes(state, { source: "revision", target: "start", sourceHandle: "return", id: "back" });

    expect(state.edges).toHaveLength(0);
  });
});
