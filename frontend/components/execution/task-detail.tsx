"use client";

import { useRef, useState } from "react";
import Link from "next/link";
import { DecisionRecord } from "@/components/analytics/decision-record";
import { ReassignAssignment } from "@/components/execution/reassign-assignment";
import { DynamicForm, ReadOnlyFields } from "@/components/execution/dynamic-form";
import { ConfirmDecisionDialog } from "@/components/execution/confirm-decision-dialog";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api";
import {
  completeTask,
  decideTask,
  IdempotencyKeys,
  instanceStatusLabel,
  reviseTask,
  stableJson,
  taskTypeLabel,
  toSubmissionData,
  type SubmissionValues,
  type TaskDecision,
  type WorkflowTask,
} from "@/lib/execution";
import { InstanceStatusBadge } from "@/components/execution/status-badge";

export function TaskDetail({
  organizationId,
  task,
  onUpdated,
}: {
  organizationId: string;
  task: WorkflowTask;
  onUpdated: () => void;
}) {
  const keys = useRef(new IdempotencyKeys());
  const [pending, setPending] = useState<TaskDecision | "revision" | "complete" | null>(null);
  const [action, setAction] = useState<Extract<TaskDecision, "approved" | "rejected"> | null>(null);
  const [conflict, setConflict] = useState<string | null>(null);
  const [banner, setBanner] = useState<string | null>(null);
  const instance = task.instance;
  const requestName = instance?.workflow?.name ?? "this request";
  const stepLabel = task.node_label ?? instance?.current_node_label ?? "current";
  const actionable = task.status === "open" && conflict === null;
  const approval = actionable && task.task_type === "approval" && task.can_decide !== false;
  const revision = actionable && task.task_type === "revision" && task.can_revise !== false;
  const assignment = actionable && task.task_type === "assignment" && task.can_complete !== false;

  async function decide(decision: Extract<TaskDecision, "approved" | "rejected">) {
    const path = `/api/v1/organizations/${organizationId}/tasks/${task.uuid}/decisions`;
    const body = { decision };
    const idempotencyKey = keys.current.for(`POST ${path} ${stableJson(body)}`);
    setPending(decision);
    setBanner(null);

    try {
      await decideTask(organizationId, task.uuid, decision, idempotencyKey);
      setAction(null);
      onUpdated();
    } catch (error) {
      if (error instanceof ApiError && error.status === 409) {
        setConflict(error.message || "This task is no longer open.");
        setAction(null);
        onUpdated();
        return;
      }

      setBanner(networkOrMessage(error));
      setAction(null);
    } finally {
      setPending(null);
    }
  }

  async function revise(values: SubmissionValues) {
    if (!instance?.form_definition) {
      return;
    }

    const data = toSubmissionData(instance.form_definition, values);
    const path = `/api/v1/organizations/${organizationId}/tasks/${task.uuid}/revisions`;
    const idempotencyKey = keys.current.for(`POST ${path} ${stableJson({ data })}`);
    setPending("revision");
    setBanner(null);

    try {
      await reviseTask(organizationId, task.uuid, data, idempotencyKey);
      onUpdated();
    } catch (error) {
      if (error instanceof ApiError && error.status === 409) {
        setConflict(error.message || "This task is no longer open.");
        onUpdated();
        return;
      }

      setBanner(networkOrMessage(error));

      if (error instanceof ApiError && error.status === 422) {
        throw error;
      }
    } finally {
      setPending(null);
    }
  }

  async function completeAssignment() {
    const path = `/api/v1/organizations/${organizationId}/tasks/${task.uuid}/complete`;
    const idempotencyKey = keys.current.for(`POST ${path} ${stableJson({})}`);
    setPending("complete");
    setBanner(null);

    try {
      await completeTask(organizationId, task.uuid, idempotencyKey);
      onUpdated();
    } catch (error) {
      if (error instanceof ApiError && error.status === 409) {
        setConflict(error.message || "This task is no longer open.");
        onUpdated();
        return;
      }

      setBanner(networkOrMessage(error));
    } finally {
      setPending(null);
    }
  }

  return (
    <div className="flex flex-col gap-5">
      {task.can_reassign ? <ReassignAssignment organizationId={organizationId} taskId={task.uuid} onUpdated={onUpdated} /> : null}
      <div className="flex flex-col gap-2">
        <div className="flex flex-wrap items-center gap-2">
          <h2 className="text-base font-medium">{requestName}</h2>
          {instance ? <InstanceStatusBadge status={instance.status} /> : null}
        </div>
        <p className="text-sm text-muted-foreground">
          {taskTypeLabel(task.task_type)} at {stepLabel}
          {instance?.workflow_version ? ` · Version ${instance.workflow_version.version_number}` : ""}
          {instance?.submitted_by ? ` · Submitted by ${instance.submitted_by.name}` : ""}
        </p>
        {instance ? (
          <Link href={`/requests/${instance.uuid}`} className="w-fit text-sm underline-offset-4 hover:underline">
            View request
          </Link>
        ) : null}
      </div>
      {conflict ? (
        <Alert variant="destructive">
          <AlertTitle>This task changed</AlertTitle>
          <AlertDescription>{conflict}</AlertDescription>
        </Alert>
      ) : null}
      {task.instructions ? <Alert><AlertTitle>{task.task_type === "revision" ? "What needs to change" : "Step instructions"}</AlertTitle><AlertDescription className="whitespace-pre-wrap">{task.instructions}</AlertDescription></Alert> : null}
      {task.status === "completed" ? (
        <Alert>
          <AlertTitle>Decision recorded</AlertTitle>
          <AlertDescription>
            {task.task_type === "assignment"
              ? "This assignment was completed."
              : task.decision === "approved"
                ? "This approval was approved."
                : task.decision === "rejected"
                  ? "This approval was rejected."
                  : "This revision was submitted."}{" "}
            {instance ? `Current status: ${instanceStatusLabel(instance.status)}.` : ""}
          </AlertDescription>
        </Alert>
      ) : null}
      <section className="flex flex-col gap-3">
        <h3 className="text-sm font-medium">Current submission</h3>
        <ReadOnlyFields definition={instance?.form_definition} data={instance?.submitted_data} />
        <DecisionRecord task={task} definition={instance?.form_definition} current={instance?.submitted_data} />
      </section>
      {revision && instance?.form_definition ? (
        <section className="flex flex-col gap-3">
          <h3 className="text-sm font-medium">Update and resubmit</h3>
          <RevisionEditor
            definition={instance.form_definition}
            initialValues={submissionDefaults(instance.form_definition, instance.submitted_data)}
            pending={pending === "revision"}
            banner={banner}
            onSubmit={revise}
          />
        </section>
      ) : null}
      {assignment ? (
        <div className="flex flex-wrap gap-2">
          <Button type="button" className="h-10" disabled={pending !== null} onClick={() => void completeAssignment()}>
            Mark complete
          </Button>
        </div>
      ) : null}
      {approval ? (
        <div className="flex flex-wrap gap-2">
          <Button type="button" className="h-10" disabled={pending !== null} onClick={() => setAction("approved")}>
            Approve
          </Button>
          <Button
            type="button"
            variant="outline"
            className="h-10"
            disabled={pending !== null}
            onClick={() => setAction("rejected")}
          >
            Reject
          </Button>
        </div>
      ) : null}
      {banner && (approval || assignment) ? (
        <p className="text-sm text-destructive" role="alert">
          {banner}
        </p>
      ) : null}
      <ConfirmDecisionDialog
        open={action !== null}
        action={action === "approved" ? "approve" : action === "rejected" ? "reject" : null}
        requestName={requestName}
        stepLabel={stepLabel}
        pending={pending === "approved" || pending === "rejected"}
        onOpenChange={(open) => {
          if (!open) {
            setAction(null);
          }
        }}
        onConfirm={() => {
          if (action) {
            void decide(action);
          }
        }}
      />
    </div>
  );
}

function RevisionEditor({
  definition,
  initialValues,
  pending,
  banner,
  onSubmit,
}: {
  definition: NonNullable<WorkflowTask["instance"]>["form_definition"];
  initialValues: SubmissionValues;
  pending: boolean;
  banner: string | null;
  onSubmit: (values: SubmissionValues) => Promise<void>;
}) {
  if (!definition) {
    return null;
  }

  return (
    <DynamicForm
      definition={definition}
      initialValues={initialValues}
      submitLabel="Resubmit"
      confirmTitle="Resubmit your updated request?"
      confirmDescription="Review the corrected answers. Submitting sends the request back to its approval step."
      pending={pending}
      banner={banner}
      onSubmit={onSubmit}
    />
  );
}

function submissionDefaults(
  definition: NonNullable<NonNullable<WorkflowTask["instance"]>["form_definition"]>,
  data: Record<string, unknown> | undefined,
): SubmissionValues {
  const values: SubmissionValues = {};

  for (const field of definition.fields) {
    if (field.type === "file") {
      continue;
    }

    const value = data?.[field.id];

    if (field.type === "checkbox") {
      values[field.id] = value === true;
      continue;
    }

    if (typeof value === "string" || typeof value === "number" || value === null) {
      values[field.id] = value;
      continue;
    }

    values[field.id] = null;
  }

  return values;
}

function networkOrMessage(error: unknown): string {
  if (error instanceof ApiError && error.status === 0) {
    return "Could not reach FlowMV. Retrying will reuse the same idempotency key.";
  }

  if (error instanceof ApiError) {
    return error.message;
  }

  return "Could not save that action.";
}
