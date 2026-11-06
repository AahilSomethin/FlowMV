"use client";

import { useEffect, useState } from "react";
import { IntentLink as Link } from "@/components/shell/intent-link";
import { BarChart3, Download } from "lucide-react";
import dynamic from "next/dynamic";
import { toast } from "sonner";
import { ChartBoundary } from "./chart-boundary";
import { WorkflowFilter } from "@/components/analytics/workflow-filter";
import { AnalyticsNav, ScopeBanner } from "@/components/analytics/analytics-frame";
import { EmptyState } from "@/components/patterns/empty-state";
import { ErrorState, describeApiError } from "@/components/patterns/error-state";
import { FilterBar } from "@/components/patterns/filter-bar";
import { LoadingState } from "@/components/patterns/loading-state";
import { PageHeader } from "@/components/patterns/page-header";
import { SectionCard } from "@/components/patterns/section-card";
import { StatCard } from "@/components/patterns/stat-card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group";
import {
  downloadExport,
  formatRate,
  formatSeconds,
  getAnalytics,
  statusColor,
  utcDay,
  type AnalyticsQuery,
  type AnalyticsReport,
} from "@/lib/analytics";
import { useAuth } from "@/lib/auth";
import { formatWhen, instanceStatusLabel, type InstanceStatus } from "@/lib/execution";

const chartLoading = () => <p role="status" className="text-sm text-muted-foreground">Loading chart</p>;
const VolumeChart = dynamic(() => import("./analytics-charts").then((module) => module.VolumeChart), { loading: chartLoading });
const StatusChart = dynamic(() => import("./analytics-charts").then((module) => module.StatusChart), { loading: chartLoading });
const DecisionChart = dynamic(() => import("./analytics-charts").then((module) => module.DecisionChart), { loading: chartLoading });

const presets = [
  { id: "7", label: "7 days", from: -6 },
  { id: "30", label: "30 days", from: -29 },
  { id: "90", label: "90 days", from: -89 },
] as const;

const statuses: Array<{ value: "all" | InstanceStatus; label: string }> = [
  { value: "all", label: "All statuses" },
  { value: "running", label: "In progress" },
  { value: "awaiting_ai", label: "AI analysis" },
  { value: "awaiting_approval", label: "Awaiting approval" },
  { value: "awaiting_revision", label: "Needs revision" },
  { value: "awaiting_assignment", label: "Awaiting assignment" },
  { value: "awaiting_delay", label: "Waiting" },
  { value: "completed", label: "Completed" },
  { value: "rejected", label: "Rejected" },
  { value: "failed", label: "Failed" },
];

export function AnalyticsScreen() {
  const { user } = useAuth();
  const organizationId = user?.current_organization?.uuid;
  const [preset, setPreset] = useState<(typeof presets)[number]["id"] | "custom">("30");
  const [from, setFrom] = useState(() => utcDay(-29));
  const [to, setTo] = useState(() => utcDay(0));
  const [workflow, setWorkflow] = useState("all");
  const [status, setStatus] = useState<"all" | InstanceStatus>("all");
  const [report, setReport] = useState<AnalyticsReport | null>(null);
  const [loadedKey, setLoadedKey] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [exporting, setExporting] = useState(false);
  const query = queryFor(from, to, workflow, status);
  const requestKey = organizationId ? `${organizationId}:${queryString(query)}` : null;

  useEffect(() => {
    if (!organizationId) {
      return;
    }

    let active = true;
    const nextQuery = queryFor(from, to, workflow, status);
    const handle = window.setTimeout(() => {
      void getAnalytics(organizationId, nextQuery)
        .then((result) => {
          if (!active) {
            return;
          }

          setReport(result);
          setError(null);
          setLoadedKey(`${organizationId}:${queryString(nextQuery)}`);
        })
        .catch((caught: unknown) => {
          if (!active) {
            return;
          }

          setError(describeApiError(caught, "Could not load analytics.").description);
          setLoadedKey(`${organizationId}:${queryString(nextQuery)}`);
        });
    }, 200);

    return () => {
      active = false;
      window.clearTimeout(handle);
    };
  }, [organizationId, from, to, workflow, status]);

  const loading = loadedKey !== requestKey;

  if (!organizationId || !loadedKey?.startsWith(`${organizationId}:`) || !report) {
    if (error && loadedKey === requestKey) {
      return <ErrorState title="Analytics unavailable" description={error} />;
    }

    return <LoadingState label="Loading analytics" />;
  }

  const statusSlices = report.status_distribution.filter((item) => item.count > 0);

  async function exportCsv() {
    if (!organizationId) {
      return;
    }

    setExporting(true);

    try {
      await downloadExport(organizationId, "analytics", query);
      toast.success("Analytics export downloaded");
    } catch (caught: unknown) {
      toast.error(caught instanceof Error ? caught.message : "The export could not be downloaded.");
    } finally {
      setExporting(false);
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        eyebrow="Insights"
        title="Analytics"
        description={`${report.from} to ${report.to} UTC. Rates use finished requests and stay hidden below ${report.minimum_sample} outcomes.`}
        actions={
          <Button type="button" variant="outline" disabled={loading || exporting} onClick={() => void exportCsv()}>
            {exporting ? <Spinner label="Exporting" /> : <Download aria-hidden="true" />}
            Export CSV
          </Button>
        }
      />
      <AnalyticsNav current="overview" />
      <FilterBar>
        <ToggleGroup
          type="single"
          value={preset === "custom" ? "" : preset}
          onValueChange={(value) => {
            const item = presets.find((entry) => entry.id === value);
            if (!item) {
              return;
            }

            setPreset(item.id);
            setFrom(utcDay(item.from));
            setTo(utcDay(0));
          }}
          aria-label="Date range"
        >
          {presets.map((item) => (
            <ToggleGroupItem key={item.id} value={item.id}>
              {item.label}
            </ToggleGroupItem>
          ))}
        </ToggleGroup>
        <Input type="date" aria-label="From date" value={from} className="w-full sm:w-40" onChange={(event) => { setPreset("custom"); setFrom(event.target.value); }} />
        <Input type="date" aria-label="To date" value={to} className="w-full sm:w-40" onChange={(event) => { setPreset("custom"); setTo(event.target.value); }} />
        <WorkflowFilter key={organizationId} organizationId={organizationId} value={workflow} onChange={setWorkflow} />
        <Select value={status} onValueChange={(value) => setStatus(value as "all" | InstanceStatus)}>
          <SelectTrigger className="w-full sm:w-56" aria-label="Filter by status">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {statuses.map((item) => (
              <SelectItem key={item.value} value={item.value}>{item.label}</SelectItem>
            ))}
          </SelectContent>
        </Select>
      </FilterBar>
      <ScopeBanner scope={report.scope} label={report.scope_label} />
      <p className="text-sm text-muted-foreground text-pretty">
        Status filtering changes the counts and the volume chart. Completion rate, rejection rate, and completion time ignore that filter.
        Approval decisions are counted separately from request outcomes.
      </p>
      {loading ? <LoadingState label="Updating analytics" /> : error ? <ErrorState title="Could not update results" description={error} /> : report.cohort.total === 0 ? (
        <EmptyState
          icon={<BarChart3 />}
          title="No requests in this range"
          description="Nothing started in this UTC window for the current scope. Counts stay at zero instead of showing a sample chart."
        />
      ) : (
        <>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <StatCard label="Requests started" value={String(report.cohort.total)} detail={report.scope_label} />
            <StatCard
              label="Completion rate"
              value={formatRate(report.completion.rate, report.completion.sufficient)}
              detail={sampleDetail(report.completion.sufficient, `${report.completion.completed} completed of ${report.completion.denominator} finished`, report.minimum_sample)}
            />
            <StatCard
              label="Rejection rate"
              value={formatRate(report.rejection.rate, report.rejection.sufficient)}
              detail={sampleDetail(report.rejection.sufficient, `${report.rejection.rejected} rejected requests, not approval clicks`, report.minimum_sample)}
            />
            <StatCard
              label="Median completion"
              value={formatSeconds(report.completion_duration.median_seconds, report.completion_duration.sufficient)}
              detail={report.completion_duration.sufficient ? `Average ${formatSeconds(report.completion_duration.average_seconds, true)}` : sampleDetail(false, `${report.completion_duration.sample_size} completed requests`, report.minimum_sample)}
            />
            <StatCard
              label="Median approval wait"
              value={formatSeconds(report.approval_wait.median_seconds, report.approval_wait.sufficient)}
              detail={waitDetail(report)}
            />
            <StatCard
              label="Revision rate"
              value={formatRate(report.revisions.rate, report.revisions.sufficient)}
              detail={`${report.revisions.instances_revised} revised · ${report.revisions.submissions} submissions${report.revisions.mean_among_revised !== null ? ` · ${report.revisions.mean_among_revised} average among those revised` : ""}`}
            />
          </div>
          <div className="grid gap-3 lg:grid-cols-5">
            <SectionCard className="lg:col-span-3" title="Requests started" description="UTC days in the selected range. Empty days are real zeros.">
              <div data-testid="volume-chart" className="h-64 min-h-52 w-full sm:h-72">
                <ChartBoundary><VolumeChart data={report.volume} /></ChartBoundary>
              </div>
            </SectionCard>
            <SectionCard className="lg:col-span-2" title="Status mix" description="Share of requests that started in this range.">
              <div data-testid="status-chart" className="mx-auto h-44 w-full max-w-56">
                <ChartBoundary><StatusChart data={statusSlices} /></ChartBoundary>
              </div>
              <ul className="mt-3 flex flex-col gap-2">
                {statusSlices.map((item) => (
                  <li key={item.status} className="flex items-center justify-between gap-3 text-sm">
                    <span className="flex min-w-0 items-center gap-2">
                      <span className="size-2.5 shrink-0 rounded-full" style={{ background: statusColor(item.status) }} />
                      <span className="truncate">{instanceStatusLabel(item.status)}</span>
                    </span>
                    <span className="tabular-nums text-muted-foreground">{item.count}{item.share !== null ? ` · ${new Intl.NumberFormat(undefined, { style: "percent", maximumFractionDigits: 0 }).format(item.share)}` : ""}</span>
                  </li>
                ))}
              </ul>
            </SectionCard>
          </div>
          <SectionCard
            title="Approval decisions"
            description={`${report.task_decisions.approved} approved and ${report.task_decisions.rejected} rejected. A rejected approval can return the request for revision, so it is not the same as a rejected request.`}
          >
            <div className="h-40 w-full">
              <ChartBoundary>
                <DecisionChart approved={report.task_decisions.approved} rejected={report.task_decisions.rejected} />
              </ChartBoundary>
            </div>
          </SectionCard>
          <section className="flex flex-col gap-3">
            <div className="flex flex-wrap items-end justify-between gap-2">
              <div>
                <h2 className="text-base font-medium">Workflow performance</h2>
                <p className="text-sm text-muted-foreground">A dash means fewer than {report.minimum_sample} finished requests, so the rate stays hidden.</p>
              </div>
              {report.truncated.workflows ? <p className="text-sm text-muted-foreground">Showing the leading workflows. Export CSV for the full set.</p> : null}
            </div>
            {report.workflows.length === 0 ? (
              <EmptyState title="No workflow activity" description="No workflow in this scope has requests in the range." />
            ) : (
              <div className="overflow-hidden rounded-xl bg-card ring-1 ring-foreground/10">
                <div className="hidden grid-cols-[minmax(11rem,2.4fr)_repeat(7,minmax(2.5rem,0.7fr))] gap-3 border-b px-4 py-2 text-xs font-medium text-muted-foreground lg:grid">
                  <span>Workflow</span><span>Started</span><span>Completed</span><span>Rejected</span><span>Open</span><span>Failed</span><span>Completion</span><span>Median</span>
                </div>
                <ul>
                  {report.workflows.map((item) => (
                    <li key={item.uuid} className="grid gap-2 border-b px-4 py-3 last:border-b-0 lg:grid-cols-[minmax(11rem,2.4fr)_repeat(7,minmax(2.5rem,0.7fr))] lg:items-center lg:gap-3">
                      <Link href={`/requests?workflow=${item.uuid}`} className="min-w-0 font-medium text-pretty underline-offset-4 hover:underline">{item.name}</Link>
                      <MetricCell label="Started" value={item.started} />
                      <MetricCell label="Completed" value={item.completed} />
                      <MetricCell label="Rejected" value={item.rejected} />
                      <MetricCell label="Open" value={item.in_progress} />
                      <MetricCell label="Failed" value={item.failed} />
                      <MetricCell label="Completion" value={formatRate(item.completion_rate, item.completion_sufficient)} />
                      <MetricCell label="Median" value={formatSeconds(item.median_completion_seconds, item.median_completion_seconds !== null)} />
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </section>
          <section className="flex flex-col gap-3">
            <div className="flex flex-wrap items-end justify-between gap-2">
              <h2 className="text-base font-medium">Approval bottlenecks</h2>
              {report.truncated.bottlenecks ? <p className="text-sm text-muted-foreground">Showing the slowest recorded steps. Export CSV for the full set.</p> : null}
            </div>
            {report.bottlenecks.length === 0 ? (
              <EmptyState title="No approval waits" description="No completed approval in this range has a recorded wait." />
            ) : (
              <ul className="overflow-hidden rounded-xl bg-card ring-1 ring-foreground/10">
                {report.bottlenecks.map((item) => (
                  <li key={`${item.workflow_uuid}:${item.node_id}`} className="grid gap-2 border-b px-4 py-3 last:border-b-0 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_auto_auto_auto] sm:items-center">
                    <span className="font-medium">{item.node_label ?? item.node_id}</span>
                    <Link href={`/requests?workflow=${item.workflow_uuid}`} className="text-sm underline-offset-4 hover:underline">{item.workflow_name}</Link>
                    <span className="text-sm tabular-nums text-muted-foreground">{item.decisions} decisions</span>
                    <span className="text-sm tabular-nums">{formatSeconds(item.median_seconds, item.sufficient)}</span>
                    <span className="text-sm tabular-nums text-muted-foreground">{item.open_count} open</span>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </>
      )}
    </div>
  );
}

function MetricCell({ label, value }: { label: string; value: string | number }) {
  const hidden = value === "Not enough completed requests";

  return (
    <span className="flex items-baseline justify-between gap-3 text-sm tabular-nums lg:block">
      <span className="text-xs text-muted-foreground lg:hidden">{label}</span>
      {hidden ? (
        <span title="Not enough completed requests">
          <span className="sr-only">Not enough completed requests</span>
          <span aria-hidden="true">—</span>
        </span>
      ) : (
        <span>{value}</span>
      )}
    </span>
  );
}

function sampleDetail(sufficient: boolean, detail: string, minimum: number): string {
  return sufficient ? detail : `${detail}. Needs at least ${minimum} outcomes.`;
}

function waitDetail(report: AnalyticsReport): string {
  const open = `${report.approval_wait.open_count} approval ${report.approval_wait.open_count === 1 ? "task is" : "tasks are"} open now`;
  const oldest = report.approval_wait.oldest_open_assigned_at ? `. Oldest assigned ${formatWhen(report.approval_wait.oldest_open_assigned_at)}` : "";

  return `${open}${oldest}`;
}

function queryFor(from: string, to: string, workflow: string, status: "all" | InstanceStatus): AnalyticsQuery {
  return {
    from,
    to,
    workflow: workflow === "all" ? undefined : workflow,
    status: status === "all" ? undefined : status,
  };
}

function queryString(query: AnalyticsQuery): string {
  return `${query.from}:${query.to}:${query.workflow ?? ""}:${query.status ?? ""}`;
}
