import { describe, expect, it } from "vitest";
import type { GraphDefinition } from "@/lib/workflows/types";
import { visibleWorkflowGraph } from "@/lib/workflows/visible-graph";

const draft: GraphDefinition = {
  nodes: [{ id: "draft-node", type: "start", position: { x: 0, y: 0 }, data: { label: "Draft", description: "" } }],
  edges: [],
};

const published: GraphDefinition = {
  nodes: [{ id: "published-node", type: "start", position: { x: 0, y: 0 }, data: { label: "Published", description: "" } }],
  edges: [],
};

const historical: GraphDefinition = {
  nodes: [{ id: "old-node", type: "start", position: { x: 0, y: 0 }, data: { label: "Old", description: "" } }],
  edges: [],
};

describe("visibleWorkflowGraph", () => {
  it("shows the saved draft that publish will use", () => {
    expect(visibleWorkflowGraph({ draft: { graph_definition: draft }, published: { graph_definition: published } }, null)).toBe(draft);
  });

  it("keeps an explicitly selected version on that snapshot", () => {
    expect(visibleWorkflowGraph({ draft: { graph_definition: draft }, published: { graph_definition: published } }, historical)).toBe(historical);
  });

  it("shows the published graph when the viewer has no draft", () => {
    expect(visibleWorkflowGraph({ published: { graph_definition: published } }, null)).toBe(published);
  });
});
