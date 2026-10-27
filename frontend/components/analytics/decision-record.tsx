import { ReadOnlyFields } from "@/components/execution/dynamic-form";
import { formatWhen, type InstanceTask } from "@/lib/execution";
import type { FormDefinition } from "@/lib/workflows/types";

export function DecisionRecord({
  task,
  definition,
  current,
}: {
  task: Pick<InstanceTask, "task_type" | "status" | "decision" | "node_label" | "decision_snapshot">;
  definition: FormDefinition | null | undefined;
  current: Record<string, unknown> | null | undefined;
}) {
  if (task.task_type !== "approval" || task.status !== "completed") {
    return null;
  }

  const snapshot = task.decision_snapshot;

  return (
    <section className="flex flex-col gap-2 rounded-lg bg-muted/40 p-3">
      <h3 className="text-sm font-medium">
        {task.node_label ?? "Approval"} · {task.decision === "approved" ? "Approved" : "Rejected"}
      </h3>
      {snapshot ? (
        sameSubmission(current, snapshot.submitted_data) ? (
          <p className="text-sm text-muted-foreground">
            Reviewed at decision {formatWhen(snapshot.captured_at)}. It matches the current submission.
          </p>
        ) : (
          <div className="flex flex-col gap-2">
            <p className="text-sm text-muted-foreground">Reviewed at decision {formatWhen(snapshot.captured_at)}.</p>
            <ReadOnlyFields definition={definition} data={snapshot.submitted_data} />
          </div>
        )
      ) : (
        <p className="text-sm text-muted-foreground">
          No decision-time snapshot was recorded for this task. The current submission may include later revisions.
        </p>
      )}
    </section>
  );
}

function sameSubmission(
  current: Record<string, unknown> | null | undefined,
  reviewed: Record<string, unknown>,
): boolean {
  return stable(current ?? {}) === stable(reviewed);
}

function stable(value: unknown): string {
  if (value === null || typeof value !== "object") {
    return JSON.stringify(value) ?? "null";
  }

  if (Array.isArray(value)) {
    return `[${value.map(stable).join(",")}]`;
  }

  const record = value as Record<string, unknown>;

  return `{${Object.keys(record)
    .sort()
    .map((key) => `${JSON.stringify(key)}:${stable(record[key])}`)
    .join(",")}}`;
}
