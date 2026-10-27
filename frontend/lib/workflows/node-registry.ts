import { defaultNodeData } from "@/lib/workflows/definition";
import type { NodeType, WorkflowNodeData } from "@/lib/workflows/types";
import { NODE_COPY, SOURCE_HANDLES } from "@/lib/workflows/types";

export const NODE_REGISTRY_VERSION = 2;

export type NodeCategoryId = "core" | "logic" | "human" | "automation";

export const NODE_CATEGORY_ORDER: NodeCategoryId[] = ["core", "logic", "human", "automation"];

export const NODE_CATEGORY_LABELS: Record<NodeCategoryId, string> = {
  core: "Core",
  logic: "Logic",
  human: "Human",
  automation: "Automation",
};

export const NODES_BY_CATEGORY: Record<NodeCategoryId, NodeType[]> = {
  core: ["start", "end"],
  logic: ["condition"],
  human: ["approval", "revision", "assignment"],
  automation: ["notification", "delay"],
};

const INTRODUCED_IN: Partial<Record<NodeType, number>> = {
  notification: 2,
  assignment: 2,
  delay: 2,
};

export function nodeRegistryVersionFor(type: NodeType): number {
  return INTRODUCED_IN[type] ?? 1;
}

export function isNodeTypeAllowed(type: NodeType, registryVersion = NODE_REGISTRY_VERSION): boolean {
  return nodeRegistryVersionFor(type) <= registryVersion;
}

export function paletteNodeTypes(registryVersion = NODE_REGISTRY_VERSION): NodeType[] {
  return NODE_CATEGORY_ORDER.flatMap((category) =>
    NODES_BY_CATEGORY[category].filter((type) => isNodeTypeAllowed(type, registryVersion)),
  );
}

export function paletteCategories(registryVersion = NODE_REGISTRY_VERSION): Array<{
  id: NodeCategoryId;
  label: string;
  types: NodeType[];
}> {
  return NODE_CATEGORY_ORDER.map((id) => ({
    id,
    label: NODE_CATEGORY_LABELS[id],
    types: NODES_BY_CATEGORY[id].filter((type) => isNodeTypeAllowed(type, registryVersion)),
  })).filter((category) => category.types.length > 0);
}

export function sourceHandlesFor(type: NodeType): string[] {
  return SOURCE_HANDLES[type];
}

export function defaultDataFor(type: NodeType): WorkflowNodeData {
  return defaultNodeData(type);
}

export function nodeTitle(type: NodeType): string {
  return NODE_COPY[type].title;
}

export function nodeDescription(type: NodeType): string {
  return NODE_COPY[type].description;
}
