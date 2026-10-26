import { createNode, snapshotKey } from "@/lib/workflows/definition";
import type { FormDefinition, FormField, GraphDefinition, NodeType, WorkflowEdge, WorkflowNode, WorkflowNodeData } from "@/lib/workflows/types";
import { SOURCE_HANDLES } from "@/lib/workflows/types";

export type EditorSnapshot = {
  nodes: WorkflowNode[];
  edges: WorkflowEdge[];
  fields: FormField[];
};

export type EditorState = EditorSnapshot & {
  past: EditorSnapshot[];
  future: EditorSnapshot[];
  savedKey: string;
  revision: number;
};

const HISTORY_LIMIT = 50;

export function createEditorState(graph: GraphDefinition, form: FormDefinition, revision: number): EditorState {
  const snapshot = {
    nodes: graph.nodes,
    edges: graph.edges,
    fields: form.fields,
  };

  return {
    ...snapshot,
    past: [],
    future: [],
    savedKey: snapshotKey({ nodes: snapshot.nodes, edges: snapshot.edges }, { fields: snapshot.fields }),
    revision,
  };
}

export function isDirty(state: EditorState): boolean {
  return snapshotKey({ nodes: state.nodes, edges: state.edges }, { fields: state.fields }) !== state.savedKey;
}

export function markSaved(state: EditorState, graph: GraphDefinition, form: FormDefinition, revision: number): EditorState {
  const next = createEditorState(graph, form, revision);

  return {
    ...next,
    past: state.past,
    future: [],
  };
}

export function addWorkflowNode(
  state: EditorState,
  type: NodeType,
  position: { x: number; y: number },
  id?: string,
): EditorState {
  if (type === "start" && state.nodes.some((node) => node.type === "start")) {
    return state;
  }

  return commit(state, {
    nodes: [...state.nodes, createNode(type, position, id)],
    edges: state.edges,
    fields: state.fields,
  });
}

export function updateNodeData(state: EditorState, id: string, patch: Partial<WorkflowNodeData>): EditorState {
  return commit(state, {
    nodes: state.nodes.map((node) => (node.id === id ? { ...node, data: { ...node.data, ...patch } } : node)),
    edges: state.edges,
    fields: state.fields,
  });
}

export function moveNodes(state: EditorState, positions: Record<string, { x: number; y: number }>, recordHistory: boolean): EditorState {
  const nodes = state.nodes.map((node) => {
    const position = positions[node.id];

    return position ? { ...node, position: { x: Math.round(position.x), y: Math.round(position.y) } } : node;
  });

  if (!recordHistory) {
    return { ...state, nodes };
  }

  return commit(state, { nodes, edges: state.edges, fields: state.fields });
}

export function removeWorkflowNode(state: EditorState, id: string): EditorState {
  return commit(state, {
    nodes: state.nodes.filter((node) => node.id !== id),
    edges: state.edges.filter((edge) => edge.source !== id && edge.target !== id),
    fields: state.fields,
  });
}

export function connectNodes(
  state: EditorState,
  connection: { source: string; target: string; sourceHandle: string; id?: string },
): EditorState {
  const source = state.nodes.find((node) => node.id === connection.source);
  const target = state.nodes.find((node) => node.id === connection.target);

  if (!source || !target || target.type === "start") {
    return state;
  }

  if (!SOURCE_HANDLES[source.type].includes(connection.sourceHandle)) {
    return state;
  }

  if (connection.sourceHandle === "return" && target.type !== "approval") {
    return state;
  }

  if (state.edges.some((edge) => edge.source === source.id && edge.source_handle === connection.sourceHandle)) {
    return state;
  }

  const edge: WorkflowEdge = {
    id: connection.id ?? crypto.randomUUID(),
    source: source.id,
    target: target.id,
    source_handle: connection.sourceHandle,
    target_handle: "in",
  };
  let nodes = state.nodes;

  if (source.type === "revision" && connection.sourceHandle === "return") {
    nodes = nodes.map((node) =>
      node.id === source.id ? { ...node, data: { ...node.data, return_to_node_id: target.id } } : node,
    );
  }

  return commit(state, { nodes, edges: [...state.edges, edge], fields: state.fields });
}

export function removeWorkflowEdge(state: EditorState, id: string): EditorState {
  return commit(state, {
    nodes: state.nodes,
    edges: state.edges.filter((edge) => edge.id !== id),
    fields: state.fields,
  });
}

export function replaceFields(state: EditorState, fields: FormField[]): EditorState {
  return commit(state, { nodes: state.nodes, edges: state.edges, fields });
}

export function remember(state: EditorState): EditorState {
  return {
    ...state,
    past: [...state.past, current(state)].slice(-HISTORY_LIMIT),
    future: [],
  };
}

export function setRevisionTarget(state: EditorState, revisionId: string, approvalId: string): EditorState {
  const existing = state.edges.find((edge) => edge.source === revisionId && edge.source_handle === "return");
  const edges = existing
    ? state.edges.map((edge) => (edge.id === existing.id ? { ...edge, target: approvalId } : edge))
    : [
        ...state.edges,
        {
          id: crypto.randomUUID(),
          source: revisionId,
          target: approvalId,
          source_handle: "return",
          target_handle: "in" as const,
        },
      ];

  return commit(state, {
    nodes: state.nodes.map((node) =>
      node.id === revisionId ? { ...node, data: { ...node.data, return_to_node_id: approvalId } } : node,
    ),
    edges,
    fields: state.fields,
  });
}

export function undo(state: EditorState): EditorState {
  const previous = state.past.at(-1);

  if (!previous) {
    return state;
  }

  return {
    ...state,
    ...previous,
    past: state.past.slice(0, -1),
    future: [current(state), ...state.future].slice(0, HISTORY_LIMIT),
  };
}

export function redo(state: EditorState): EditorState {
  const next = state.future[0];

  if (!next) {
    return state;
  }

  return {
    ...state,
    ...next,
    past: [...state.past, current(state)].slice(-HISTORY_LIMIT),
    future: state.future.slice(1),
  };
}

function commit(state: EditorState, snapshot: EditorSnapshot): EditorState {
  return {
    ...state,
    ...snapshot,
    past: [...state.past, current(state)].slice(-HISTORY_LIMIT),
    future: [],
  };
}

function current(state: EditorState): EditorSnapshot {
  return {
    nodes: state.nodes,
    edges: state.edges,
    fields: state.fields,
  };
}
