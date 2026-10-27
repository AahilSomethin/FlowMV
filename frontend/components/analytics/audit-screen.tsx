"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { ScrollText } from "lucide-react";
import { WorkflowFilter } from "@/components/analytics/workflow-filter";
import { AnalyticsNav, ScopeBanner } from "@/components/analytics/analytics-frame";
import { ListPager } from "@/components/execution/list-pager";
import { EmptyState } from "@/components/patterns/empty-state";
import { ErrorState, describeApiError } from "@/components/patterns/error-state";
import { LoadingState } from "@/components/patterns/loading-state";
import { PageHeader } from "@/components/patterns/page-header";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { downloadExport, getAudit, utcDay, type AuditPage } from "@/lib/analytics";
import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { eventLabel, formatWhen } from "@/lib/execution";

const eventTypes = [
  "instance_started",
  "condition_evaluated",
  "task_created",
  "task_approved",
  "task_rejected",
  "revision_requested",
  "revision_submitted",
  "revision_exhausted",
  "instance_completed",
  "instance_rejected",
  "instance_failed",
  "task_completed",
  "task_reassigned",
  "notification_queued",
  "notification_sent",
  "notification_failed",
  "delay_started",
  "delay_resumed",
  "delay_cancelled",
] as const;

export function AuditScreen() {
  const { user } = useAuth();
  const organizationId = user?.current_organization?.uuid;
  const [from, setFrom] = useState(() => utcDay(-29));
  const [to, setTo] = useState(() => utcDay(0));
  const [workflow, setWorkflow] = useState("all");
  const [eventType, setEventType] = useState("all");
  const [actor, setActor] = useState("all");
  const [instance, setInstance] = useState("");
  const [page, setPage] = useState(1);
  const [actors, setActors] = useState<Array<{ uuid: string; name: string }>>([]);
  const [report, setReport] = useState<AuditPage | null>(null);
  const [loadedKey, setLoadedKey] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const requestKey = organizationId
    ? `${organizationId}:${from}:${to}:${workflow}:${eventType}:${actor}:${instance.trim()}:${page}`
    : null;

  useEffect(() => {
    if (!organizationId) {
      return;
    }

    let active = true;
    void api<Array<{ uuid: string; name: string }>>(`/api/v1/organizations/${organizationId}/members`)
      .then((result) => {
        if (active) {
          setActors(result.map((item) => ({ uuid: item.uuid, name: item.name })));
        }
      })
      .catch(() => {
        if (active) {
          setActors([]);
        }
      });

    return () => {
      active = false;
    };
  }, [organizationId]);

  useEffect(() => {
    if (!organizationId) {
      return;
    }

    let active = true;
    const handle = window.setTimeout(() => {
      void getAudit(organizationId, {
        from,
        to,
        page,
        workflow: workflow === "all" ? undefined : workflow,
        event_type: eventType === "all" ? undefined : eventType,
        actor: actor === "all" ? undefined : actor,
        instance: instance.trim() || undefined,
      })
        .then((result) => {
          if (!active) {
            return;
          }

          setReport(result);
          setError(null);
          setLoadedKey(requestKey);
        })
        .catch((caught: unknown) => {
          if (!active) {
            return;
          }

          setError(describeApiError(caught, "Could not load the audit log.").description);
          setLoadedKey(requestKey);
        });
    }, 200);

    return () => {
      active = false;
      window.clearTimeout(handle);
    };
  }, [organizationId, from, to, workflow, eventType, actor, instance, page, requestKey]);

  const loading = loadedKey !== requestKey;

  if (!organizationId || !loadedKey?.startsWith(`${organizationId}:`) || !report) {
    if (error && loadedKey === requestKey) {
      return <ErrorState title="Audit unavailable" description={error} />;
    }

    return <LoadingState label="Loading audit" />;
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Audit"
        description="Append-only workflow events for requests you can open. Submission values are not shown here."
        actions={
          <Button
            type="button"
            variant="outline"
            className="h-10"
            disabled={loading}
            onClick={() =>
              void downloadExport(organizationId, "audit", {
                from,
                to,
                workflow: workflow === "all" ? undefined : workflow,
                event_type: eventType === "all" ? undefined : eventType,
                actor: actor === "all" ? undefined : actor,
                instance: instance.trim() || undefined,
              })
            }
          >
            Export CSV
          </Button>
        }
      />
      <AnalyticsNav current="audit" />
      <div className="flex flex-col gap-2 lg:flex-row lg:flex-wrap">
        <Input type="date" aria-label="From date" value={from} className="h-10 w-full sm:w-44" onChange={(event) => { setPage(1); setFrom(event.target.value); }} />
        <Input type="date" aria-label="To date" value={to} className="h-10 w-full sm:w-44" onChange={(event) => { setPage(1); setTo(event.target.value); }} />
        <WorkflowFilter key={organizationId} organizationId={organizationId} value={workflow} onChange={(value) => { setPage(1); setWorkflow(value); }} />
        <Select value={eventType} onValueChange={(value) => { setPage(1); setEventType(value); }}>
          <SelectTrigger className="h-10 w-full sm:w-56" aria-label="Filter by event type">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All events</SelectItem>
            {eventTypes.map((item) => (
              <SelectItem key={item} value={item}>{eventLabel(item)}</SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Select value={actor} onValueChange={(value) => { setPage(1); setActor(value); }}>
          <SelectTrigger className="h-10 w-full sm:w-56" aria-label="Filter by actor">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">Anyone</SelectItem>
            <SelectItem value="system">FlowMV</SelectItem>
            {actors.map((item) => (
              <SelectItem key={item.uuid} value={item.uuid}>{item.name}</SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Input
          value={instance}
          aria-label="Request id"
          placeholder="Request id"
          className="h-10 w-full sm:max-w-xs"
          onChange={(event) => {
            setPage(1);
            setInstance(event.target.value);
          }}
        />
      </div>
      <ScopeBanner scope={report.scope} label={report.scope_label} />
      {loading ? <LoadingState label="Updating audit" /> : error ? <ErrorState title="Could not update results" description={error} /> : report.items.length === 0 ? (
        <EmptyState
          icon={<ScrollText />}
          title="No audit events"
          description="No recorded events match these filters. The log itself is unchanged."
        />
      ) : (
        <>
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>When</TableHead>
                  <TableHead>Event</TableHead>
                  <TableHead>Request</TableHead>
                  <TableHead>Actor</TableHead>
                  <TableHead>Detail</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {report.items.map((item) => (
                  <TableRow key={item.uuid}>
                    <TableCell>{formatWhen(item.created_at)}</TableCell>
                    <TableCell>{eventLabel(item.event_type)}</TableCell>
                    <TableCell>
                      {item.instance_uuid ? (
                        <Link href={`/requests/${item.instance_uuid}`} className="underline-offset-4 hover:underline">
                          {item.workflow_name ?? "Request"}
                        </Link>
                      ) : (
                        item.workflow_name ?? "Request"
                      )}
                      {item.node_label ? <div className="text-muted-foreground">{item.node_label}</div> : null}
                    </TableCell>
                    <TableCell>{item.actor_name ?? "FlowMV"}</TableCell>
                    <TableCell className="text-muted-foreground">{payloadText(item.payload)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
          <ListPager page={page} lastPage={report.last_page} total={report.total} label="events" onPage={setPage} />
        </>
      )}
    </div>
  );
}

function payloadText(payload: Record<string, unknown>): string {
  const parts: string[] = [];

  if (typeof payload.decision === "string") {
    parts.push(payload.decision);
  }

  if (Array.isArray(payload.changed_field_ids) && payload.changed_field_ids.length > 0) {
    parts.push(`fields ${payload.changed_field_ids.join(", ")}`);
  }

  if (typeof payload.failure_code === "string") {
    parts.push(payload.failure_code);
  }

  if (typeof payload.result === "string") {
    parts.push(payload.result);
  }

  return parts.join(" · ");
}
