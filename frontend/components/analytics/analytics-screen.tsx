"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { BarChart3 } from "lucide-react";
import { CartesianGrid, Cell, Line, LineChart, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { AnalyticsNav, ScopeBanner } from "@/components/analytics/analytics-frame";
import { EmptyState } from "@/components/patterns/empty-state";
import { ErrorState, describeApiError } from "@/components/patterns/error-state";
import { LoadingState } from "@/components/patterns/loading-state";
import { PageHeader } from "@/components/patterns/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
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
import { instanceStatusLabel, listPublishedWorkflows, type InstanceStatus } from "@/lib/execution";

const presets = [
  { id: "7", label: "7 days", from: -6 },
  { id: "30", label: "30 days", from: -29 },
  { id: "90", label: "90 days", from: -89 },
] as const;

const statuses: Array<{ value: "all" | InstanceStatus; label: string }> = [
  { value: "all", label: "All statuses" },
  { value: "running", label: "In progress" },
  { value: "awaiting_approval", label: "Awaiting approval" },
  { value: "awaiting_revision", label: "Needs revision" },
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
  const [workflows, setWorkflows] = useState<Array<{ uuid: string; name: string }>>([]);
  const [report, setReport] = useState<AnalyticsReport | null>(null);
  const [loadedKey, setLoadedKey] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const query = queryFor(from, to, workflow, status);
  const requestKey = organizationId ? `${organizationId}:${queryString(query)}` : null;

  useEffect(() => {
    if (!organizationId) {
      return;
    }

    let active = true;
    void listPublishedWorkflows(organizationId, "", 1)
      .then((result) => {
        if (active) {
          setWorkflows(result.items.filter((item) => item.name));
        }
      })
      .catch(() => {
        if (active) {
          setWorkflows([]);
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

          setReport(null);
          setError(describeApiError(caught, "Could not load analytics.").description);
          setLoadedKey(`${organizationId}:${queryString(nextQuery)}`);
        });
    }, 200);

    return () => {
      active = false;
      window.clearTimeout(handle);
    };
  }, [organizationId, from, to, workflow, status]);

  if (!organizationId || loadedKey !== requestKey || !report) {
    if (error && loadedKey === requestKey) {
      return <ErrorState title="Analytics unavailable" description={error} />;
    }

    return <LoadingState label="Loading analytics" />;
  }

  const statusSlices = report.status_distribution.filter((item) => item.count > 0);

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Analytics"
        description={`${report.from} to ${report.to} UTC. Completion rate uses finished requests only and is hidden below five outcomes.`}
        actions={
          <Button
            type="button"
            variant="outline"
            className="h-10"
            onClick={() => void downloadExport(organizationId, "analytics", query)}
          >
            Export CSV
          </Button>
        }
      />
      <AnalyticsNav current="overview" />
      <div className="flex flex-col gap-2 lg:flex-row lg:flex-wrap">
        {presets.map((item) => (
          <Button
            key={item.id}
            type="button"
            variant={preset === item.id ? "default" : "outline"}
            className="h-10"
            onClick={() => {
              setPreset(item.id);
              setFrom(utcDay(item.from));
              setTo(utcDay(0));
            }}
          >
            {item.label}
          </Button>
        ))}
        <Input
          type="date"
          aria-label="From date"
          value={from}
          className="h-10 w-full sm:w-44"
          onChange={(event) => {
            setPreset("custom");
            setFrom(event.target.value);
          }}
        />
        <Input
          type="date"
          aria-label="To date"
          value={to}
          className="h-10 w-full sm:w-44"
          onChange={(event) => {
            setPreset("custom");
            setTo(event.target.value);
          }}
        />
        <Select value={workflow} onValueChange={setWorkflow}>
          <SelectTrigger className="h-10 w-full sm:w-56" aria-label="Filter by workflow">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All workflows</SelectItem>
            {workflows.map((item) => (
              <SelectItem key={item.uuid} value={item.uuid}>
                {item.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Select
          value={status}
          onValueChange={(value) => setStatus(value as "all" | InstanceStatus)}
        >
          <SelectTrigger className="h-10 w-full sm:w-56" aria-label="Filter by status">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {statuses.map((item) => (
              <SelectItem key={item.value} value={item.value}>
                {item.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <ScopeBanner scope={report.scope} label={report.scope_label} />
      <p className="text-sm text-muted-foreground">
        Status filtering changes the counts and the volume chart. Completion rate, rejection rate, and completion time ignore that filter.
        Approval decisions are counted separately from request outcomes.
      </p>
      {report.cohort.total === 0 ? (
        <EmptyState
          icon={<BarChart3 />}
          title="No requests in this range"
          description="Nothing started in this UTC window for the current scope. Counts stay at zero instead of showing a sample chart."
        />
      ) : (
        <>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Metric title="Requests" value={String(report.cohort.total)} detail={report.scope_label} />
            <Metric
              title="Completion rate"
              value={formatRate(report.completion.rate, report.completion.sufficient)}
              detail={`${report.completion.completed} completed of ${report.completion.denominator} finished`}
            />
            <Metric
              title="Rejection rate"
              value={formatRate(report.rejection.rate, report.rejection.sufficient)}
              detail={`${report.rejection.rejected} rejected requests, not approval clicks`}
            />
            <Metric
              title="Median completion"
              value={formatSeconds(report.completion_duration.median_seconds, report.completion_duration.sufficient)}
              detail={
                report.completion_duration.sufficient
                  ? `Average ${formatSeconds(report.completion_duration.average_seconds, true)}`
                  : `${report.completion_duration.sample_size} completed requests`
              }
            />
          </div>
          <div className="grid gap-3 lg:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle>Requests started</CardTitle>
                <CardDescription>UTC days in the selected range. Empty days are real zeros.</CardDescription>
              </CardHeader>
              <CardContent className="h-64 min-h-48">
                <div data-testid="volume-chart" className="h-full">
                  <ResponsiveContainer width="100%" height="100%">
                    <LineChart data={report.volume}>
                      <CartesianGrid stroke="var(--border)" />
                      <XAxis dataKey="date" stroke="var(--muted-foreground)" tick={{ fill: "var(--muted-foreground)", fontSize: 12 }} />
                      <YAxis allowDecimals={false} stroke="var(--muted-foreground)" tick={{ fill: "var(--muted-foreground)", fontSize: 12 }} />
                      <Tooltip />
                      <Line type="monotone" dataKey="count" stroke="var(--primary)" strokeWidth={2} dot={false} />
                    </LineChart>
                  </ResponsiveContainer>
                </div>
              </CardContent>
            </Card>
            <Card>
              <CardHeader>
                <CardTitle>Status</CardTitle>
                <CardDescription>Share of requests that started in this range.</CardDescription>
              </CardHeader>
              <CardContent className="h-64 min-h-48">
                <div data-testid="status-chart" className="h-full">
                  <ResponsiveContainer width="100%" height="100%">
                    <PieChart>
                      <Pie data={statusSlices} dataKey="count" nameKey="status" innerRadius={48} outerRadius={72}>
                        {statusSlices.map((item) => (
                          <Cell key={item.status} fill={statusColor(item.status)} />
                        ))}
                      </Pie>
                      <Tooltip />
                    </PieChart>
                  </ResponsiveContainer>
                </div>
                <ul className="flex flex-wrap gap-3">
                  {statusSlices.map((item) => (
                    <li key={item.status} className="text-sm text-muted-foreground">
                      {instanceStatusLabel(item.status)} {item.count}
                    </li>
                  ))}
                </ul>
              </CardContent>
            </Card>
          </div>
          <Card>
            <CardHeader>
              <CardTitle>Approval decisions</CardTitle>
              <CardDescription>
                {report.task_decisions.approved} approved and {report.task_decisions.rejected} rejected. A rejected approval can return the request for revision, so it is not the same as a rejected request.
              </CardDescription>
            </CardHeader>
            <CardContent className="text-sm text-muted-foreground">
              Median approval wait {formatSeconds(report.approval_wait.median_seconds, report.approval_wait.sufficient)}.{" "}
              {report.approval_wait.open_count} approval {report.approval_wait.open_count === 1 ? "task is" : "tasks are"} open now.
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle>Revisions</CardTitle>
              <CardDescription>
                {report.revisions.sufficient
                  ? `${formatRate(report.revisions.rate, true)} of requests in the range were revised.`
                  : "Not enough requests to show a revision rate."}
              </CardDescription>
            </CardHeader>
            <CardContent className="text-sm text-muted-foreground">
              {report.revisions.instances_revised} requests were revised, with {report.revisions.submissions} submissions
              {report.revisions.mean_among_revised !== null
                ? ` and ${report.revisions.mean_among_revised} submissions on average among those requests.`
                : "."}
            </CardContent>
          </Card>
          <section className="flex flex-col gap-3">
            <h2 className="text-base font-medium">Workflow performance</h2>
            {report.workflows.length === 0 ? (
              <EmptyState title="No workflow activity" description="No workflow in this scope has requests in the range." />
            ) : (
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Workflow</TableHead>
                      <TableHead>Started</TableHead>
                      <TableHead>Completed</TableHead>
                      <TableHead>Rejected</TableHead>
                      <TableHead>Open</TableHead>
                      <TableHead>Failed</TableHead>
                      <TableHead>Completion rate</TableHead>
                      <TableHead>Median time</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {report.workflows.map((item) => (
                      <TableRow key={item.uuid}>
                        <TableCell>
                          <Link href={`/requests?workflow=${item.uuid}`} className="font-medium underline-offset-4 hover:underline">
                            {item.name}
                          </Link>
                        </TableCell>
                        <TableCell>{item.started}</TableCell>
                        <TableCell>{item.completed}</TableCell>
                        <TableCell>{item.rejected}</TableCell>
                        <TableCell>{item.in_progress}</TableCell>
                        <TableCell>{item.failed}</TableCell>
                        <TableCell>{formatRate(item.completion_rate, item.completion_sufficient)}</TableCell>
                        <TableCell>{formatSeconds(item.median_completion_seconds, item.median_completion_seconds !== null)}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            )}
          </section>
          <section className="flex flex-col gap-3">
            <h2 className="text-base font-medium">Approval bottlenecks</h2>
            {report.bottlenecks.length === 0 ? (
              <EmptyState title="No approval waits" description="No completed approval in this range has a recorded wait." />
            ) : (
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Step</TableHead>
                      <TableHead>Workflow</TableHead>
                      <TableHead>Decisions</TableHead>
                      <TableHead>Median wait</TableHead>
                      <TableHead>Open now</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {report.bottlenecks.map((item) => (
                      <TableRow key={`${item.workflow_uuid}:${item.node_id}`}>
                        <TableCell>{item.node_label ?? item.node_id}</TableCell>
                        <TableCell>
                          <Link href={`/requests?workflow=${item.workflow_uuid}`} className="underline-offset-4 hover:underline">
                            {item.workflow_name}
                          </Link>
                        </TableCell>
                        <TableCell>{item.decisions}</TableCell>
                        <TableCell>{formatSeconds(item.median_seconds, item.sufficient)}</TableCell>
                        <TableCell>{item.open_count}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            )}
          </section>
        </>
      )}
    </div>
  );
}

function Metric({ title, value, detail }: { title: string; value: string; detail: string }) {
  return (
    <Card>
      <CardHeader>
        <CardDescription>{title}</CardDescription>
        <CardTitle className="text-xl">{value}</CardTitle>
      </CardHeader>
      <CardContent className="text-sm text-muted-foreground">{detail}</CardContent>
    </Card>
  );
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
