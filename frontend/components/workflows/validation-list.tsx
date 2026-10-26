import type { WorkflowViolation } from "@/lib/workflows/types";

export function ValidationList({ violations }: { violations: WorkflowViolation[] }) {
  if (violations.length === 0) {
    return null;
  }

  return (
    <div className="rounded-lg bg-destructive/10 px-3 py-2" role="alert">
      <p className="text-sm font-medium text-destructive">This draft cannot be published yet.</p>
      <ul className="mt-2 flex max-h-40 flex-col gap-1 overflow-y-auto">
        {violations.map((violation) => (
          <li key={`${violation.code}-${violation.node_id}-${violation.edge_id}-${violation.field_id}-${violation.message}`} className="text-sm text-foreground">
            {violation.message}
          </li>
        ))}
      </ul>
    </div>
  );
}
