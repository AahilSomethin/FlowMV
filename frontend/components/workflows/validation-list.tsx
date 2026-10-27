import type { WorkflowNode, WorkflowViolation } from "@/lib/workflows/types";

export function ValidationList({ violations, nodes = [], onSelect }: { violations: WorkflowViolation[]; nodes?: WorkflowNode[]; onSelect?: (id: string) => void }) {
  if (violations.length === 0) {
    return null;
  }

  return (
    <div className="rounded-lg bg-destructive/10 px-3 py-2" role="alert">
      <p className="text-sm font-medium text-destructive">This draft cannot be published yet.</p>
      <ul className="mt-2 flex max-h-40 flex-col gap-1 overflow-y-auto">
        {violations.map((violation) => (
          <li key={`${violation.code}-${violation.node_id}-${violation.edge_id}-${violation.field_id}-${violation.message}`} className="text-sm text-foreground">
            {violation.node_id && onSelect ? <button type="button" className="text-left underline decoration-destructive/40 underline-offset-4 hover:decoration-destructive focus-visible:outline-2 focus-visible:outline-ring" onClick={() => onSelect(violation.node_id!)}>{nodes.find((node) => node.id === violation.node_id)?.data.label || "Step"}: {violation.message}</button> : violation.message}
          </li>
        ))}
      </ul>
    </div>
  );
}
