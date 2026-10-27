"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { DecisionRecord } from "@/components/analytics/decision-record";
import { ReadOnlyFields } from "@/components/execution/dynamic-form";
import { InstanceStatusBadge } from "@/components/execution/status-badge";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { ErrorState, describeApiError } from "@/components/patterns/error-state";
import { LoadingState } from "@/components/patterns/loading-state";
import { PageHeader } from "@/components/patterns/page-header";
import {
  eventLabel,
  failureLabel,
  formatWhen,
  getInstance,
  IdempotencyKeys,
  instanceStatusLabel,
  retryInstance,
  stableJson,
  type WorkflowInstance,
} from "@/lib/execution";

export function RequestDetailScreen() {
  const params = useParams<{ instanceId: string }>();
  const { user } = useAuth();
  const organizationId = user?.current_organization?.uuid;
  const role = user?.current_organization?.role;
  const keys = useRef(new IdempotencyKeys());
  const [instance, setInstance] = useState<WorkflowInstance | null>(null);
  const [loadedKey, setLoadedKey] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [conflict, setConflict] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const requestKey = organizationId && params.instanceId ? `${organizationId}:${params.instanceId}` : null;

  useEffect(() => {
    if (!organizationId || !params.instanceId) {
      return;
    }

    let active = true;
    const key = `${organizationId}:${params.instanceId}`;

    void getInstance(organizationId, params.instanceId)
      .then((result) => {
        if (!active) {
          return;
        }

        setInstance(result);
        setError(null);
        setLoadedKey(key);
      })
      .catch((caught: unknown) => {
        if (!active) {
          return;
        }

        setInstance(null);
        setError(describeApiError(caught, "Could not load this request.").description);
        setLoadedKey(key);
      });

    return () => {
      active = false;
    };
  }, [organizationId, params.instanceId]);

  if (!requestKey || loadedKey !== requestKey) {
    return <LoadingState label="Loading request" />;
  }

  if (error || !instance) {
    return <ErrorState title="Request unavailable" description={error ?? "That request is not available."} />;
  }

  const current = instance;
  const terminal =
    current.status === "completed" || current.status === "rejected" || current.status === "failed";

  async function retry() {
    if (!organizationId) {
      return;
    }

    const path = `/api/v1/organizations/${organizationId}/instances/${current.uuid}/retry`;
    const idempotencyKey = keys.current.for(`POST ${path} ${stableJson({})}`);
    setPending(true);
    setConflict(null);

    try {
      const next = await retryInstance(organizationId, current.uuid, idempotencyKey);
      setInstance(next);
    } catch (caught) {
      if (caught instanceof ApiError && caught.status === 409) {
        setConflict(caught.message || "This request can no longer be retried.");
        if (organizationId && params.instanceId) {
          const refreshed = await getInstance(organizationId, params.instanceId);
          setInstance(refreshed);
        }

        return;
      }

      setConflict(caught instanceof ApiError && caught.status === 0
        ? "Could not reach FlowMV. Retrying will reuse the same idempotency key."
        : describeApiError(caught, "Could not retry this request.").description);
    } finally {
      setPending(false);
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={instance.workflow?.name ?? "Request"}
        description={`Started ${formatWhen(instance.started_at)}${instance.submitted_by ? ` by ${instance.submitted_by.name}` : ""}.`}
        actions={
          <Button variant="outline" className="h-10" asChild>
            <Link href="/requests">All requests</Link>
          </Button>
        }
      />
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
        <div className="flex flex-col gap-4">
          {terminal ? (
            <Alert variant={instance.status === "completed" ? "default" : "destructive"}>
              <AlertTitle>{instanceStatusLabel(instance.status)}</AlertTitle>
              <AlertDescription>
                {instance.status === "failed"
                  ? failureLabel(instance.failure_code)
                  : instance.status === "rejected"
                    ? "This request was rejected and is no longer moving through the workflow."
                    : "This request is complete."}
              </AlertDescription>
            </Alert>
          ) : null}
          {instance.status === "awaiting_delay" || instance.status === "awaiting_revision" || instance.status === "awaiting_assignment" || instance.status === "awaiting_approval" ? <Alert><AlertTitle>{instanceStatusLabel(instance.status)}</AlertTitle><AlertDescription>{instance.status === "awaiting_delay" ? "The workflow is paused for a configured waiting period. It continues automatically when that period ends." : instance.status === "awaiting_revision" ? "The submitter needs to update their answers. Open My Tasks to review any revision assigned to you." : instance.status === "awaiting_assignment" ? "An assigned task needs to be completed before this request continues." : "This request is waiting for a decision at its current approval step."}</AlertDescription></Alert> : null}
          {conflict ? (
            <Alert variant="destructive">
              <AlertTitle>This request changed</AlertTitle>
              <AlertDescription>{conflict}</AlertDescription>
            </Alert>
          ) : null}
          <section className="flex flex-col gap-3 rounded-xl bg-card p-4 ring-1 ring-foreground/10">
            <h2 className="text-sm font-medium">Summary</h2>
            <dl className="grid gap-3 sm:grid-cols-2">
              <SummaryItem label="Status">
                <InstanceStatusBadge status={instance.status} />
              </SummaryItem>
              <SummaryItem label="Current step">{instance.current_node_label ?? "Not recorded"}</SummaryItem>
              {(instance.tasks ?? []).some((task) => task.status === "open" && task.assigned_role) ? <SummaryItem label="Next action by">{[...new Set((instance.tasks ?? []).filter((task) => task.status === "open" && task.assigned_role).map((task) => task.assigned_role === "admin" ? "Administrator" : task.assigned_role === "manager" ? "Manager" : "Employee"))].join(", ")}</SummaryItem> : null}
              <SummaryItem label="Workflow version">
                {instance.workflow_version ? `Version ${instance.workflow_version.version_number}` : "Not recorded"}
              </SummaryItem>
              <SummaryItem label="Completed">{formatWhen(instance.completed_at)}</SummaryItem>
            </dl>
            {role === "admin" && instance.retryable ? (
              <Button type="button" className="h-10 w-fit" disabled={pending} onClick={() => void retry()}>
                {pending ? "Retrying" : "Retry request"}
              </Button>
            ) : null}
          </section>
          <section className="flex flex-col gap-3 rounded-xl bg-card p-4 ring-1 ring-foreground/10">
            <h2 className="text-sm font-medium">Current submission</h2>
            <ReadOnlyFields definition={instance.form_definition} data={instance.submitted_data} />
            {(instance.tasks ?? [])
              .filter((task) => task.task_type === "approval" && task.status === "completed")
              .map((task) => (
                <DecisionRecord
                  key={task.uuid}
                  task={task}
                  definition={instance.form_definition}
                  current={instance.submitted_data}
                />
              ))}
          </section>
          <section className="flex flex-col gap-3 rounded-xl bg-card p-4 ring-1 ring-foreground/10">
            <h2 className="text-sm font-medium">Decisions</h2>
            {(instance.tasks ?? []).length === 0 ? (
              <p className="text-sm text-muted-foreground">No tasks have been recorded.</p>
            ) : (
              <ul className="flex flex-col gap-3">
                {(instance.tasks ?? []).map((task) => (
                  <li key={task.uuid} className="flex flex-col gap-1 text-sm">
                    <span className="font-medium">
                      {task.task_type === "approval" ? "Approval" : task.task_type === "assignment" ? "Assignment" : "Revision"} ·{" "}
                      {task.node_label ?? "Workflow step"}
                    </span>
                    {role === "admin" && task.status === "open" && task.task_type === "assignment" ? <Link className="underline underline-offset-4" href={`/tasks/${task.uuid}`}>Manage assignment</Link> : null}
                    <span className="text-muted-foreground">
                      {task.status === "open"
                        ? "Open"
                        : task.task_type === "assignment"
                          ? "Completed"
                          : task.decision === "approved"
                            ? "Approved"
                            : task.decision === "rejected"
                              ? "Rejected"
                              : "Submitted"}
                      {task.completed_at ? ` · ${formatWhen(task.completed_at)}` : ""}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>
        <section className="flex flex-col gap-3 rounded-xl bg-card p-4 ring-1 ring-foreground/10">
          <h2 className="text-sm font-medium">Activity</h2>
          <ol className="flex flex-col gap-4">
            {[...(instance.events ?? [])].sort((a, b) => Date.parse(a.created_at) - Date.parse(b.created_at)).map((event) => (
              <li key={event.uuid} className="relative flex flex-col gap-1 border-l-2 border-primary/20 pb-3 pl-5 before:absolute before:top-1 before:-left-[5px] before:size-2 before:rounded-full before:bg-primary">
                <span className="text-sm font-medium">{eventLabel(event.event_type)}</span>
                <span className="text-sm text-muted-foreground">
                  {event.node_label ? `${event.node_label} · ` : ""}
                  {event.actor?.name ?? "FlowMV"} · {formatWhen(event.created_at)}
                </span>
              </li>
            ))}
          </ol>
        </section>
      </div>
    </div>
  );
}

function SummaryItem({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-sm text-muted-foreground">{label}</dt>
      <dd className="text-sm">{children}</dd>
    </div>
  );
}
