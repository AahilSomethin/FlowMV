import { describe, expect, it } from "vitest";
import { snapshotKey, validateDefinition } from "@/lib/workflows/definition";
import {
  addWorkflowNode,
  connectNodes,
  createEditorState,
  isDirty,
  markSaved,
  undo,
  updateNodeData,
  setWorkflowConnection,
} from "@/lib/workflows/editor-state";

describe("workflow editor state", () => {
  it("replaces a connection as one undoable change and synchronizes revision targets", () => {
    let state = createEditorState({ nodes: [], edges: [] }, { fields: [] }, 1);
    state = addWorkflowNode(state, "revision", { x: 0, y: 0 }, "revision");
    state = addWorkflowNode(state, "approval", { x: 336, y: 0 }, "first");
    state = addWorkflowNode(state, "approval", { x: 672, y: 0 }, "second");
    state = setWorkflowConnection(state, "revision", "return", "first");
    const replaced = setWorkflowConnection(state, "revision", "return", "second");
    expect(replaced.edges).toHaveLength(1);
    expect(replaced.edges[0].target).toBe("second");
    expect(replaced.nodes[0].data.return_to_node_id).toBe("second");
    expect(undo(replaced).edges[0].target).toBe("first");
    const disconnected = setWorkflowConnection(replaced, "revision", "return", "");
    expect(disconnected.edges).toHaveLength(0);
    expect(disconnected.nodes[0].data.return_to_node_id).toBeNull();
    expect(setWorkflowConnection(state, "revision", "return", "revision")).toBe(state);
  });
  it("preserves edits made while a draft save is in flight", () => {
    let submitted = createEditorState({ nodes: [], edges: [] }, { fields: [] }, 1);
    submitted = addWorkflowNode(submitted, "approval", { x: 0, y: 0 }, "approval");
    const graph = { nodes: submitted.nodes, edges: submitted.edges };
    const form = { fields: submitted.fields };
    const edited = updateNodeData(submitted, "approval", { label: "A newer edit" });
    const saved = markSaved(edited, graph, form, 2, snapshotKey(graph, form));
    expect(saved.nodes[0].data.label).toBe("A newer edit");
    expect(saved.revision).toBe(2);
    expect(isDirty(saved)).toBe(true);
    expect(isDirty(undo(saved))).toBe(false);
  });
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
