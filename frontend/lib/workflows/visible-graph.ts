import type { GraphDefinition } from "@/lib/workflows/types";

type GraphSource = {
  draft?: { graph_definition: GraphDefinition } | null;
  published?: { graph_definition: GraphDefinition } | null;
};

/**
 * The detail screen publishes the saved draft. The default preview has to be
 * that draft, while an explicitly opened version stays on its snapshot.
 */
export function visibleWorkflowGraph(workflow: GraphSource, selected: GraphDefinition | null): GraphDefinition | null {
  if (selected) {
    return selected;
  }

  return workflow.draft?.graph_definition ?? workflow.published?.graph_definition ?? null;
}
