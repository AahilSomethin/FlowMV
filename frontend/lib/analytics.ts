import { api } from "@/lib/api";
import type { InstanceStatus } from "@/lib/execution";

export type AnalyticsScope = "organization" | "visible_requests";

export type AnalyticsReport = {
  timezone: "UTC";
  scope: AnalyticsScope;
  scope_label: string;
  from: string;
  to: string;
  minimum_sample: number;
  filters: { workflow: string | null; status: string | null };
  cohort: { total: number };
  status_distribution: Array<{ status: InstanceStatus; count: number; share: number | null }>;
  volume: Array<{ date: string; count: number }>;
  completion: Rate;
  rejection: { rejected: number; denominator: number; rate: number | null; sufficient: boolean };
  completion_duration: DurationStat;
  approval_wait: DurationStat & { open_count: number; oldest_open_assigned_at: string | null };
  workflows: WorkflowPerformance[];
  revisions: {
    cohort_size: number;
    instances_revised: number;
    submissions: number;
    rate: number | null;
    sufficient: boolean;
    mean_among_revised: number | null;
  };
  task_decisions: { approved: number; rejected: number };
  bottlenecks: Bottleneck[];
  truncated: { workflows: boolean; bottlenecks: boolean };
};

type Rate = {
  completed: number;
  rejected: number;
  denominator: number;
  rate: number | null;
  sufficient: boolean;
};

type DurationStat = {
  sample_size: number;
  average_seconds: number | null;
  median_seconds: number | null;
  sufficient: boolean;
};

export type WorkflowPerformance = {
  uuid: string;
  name: string;
  started: number;
  completed: number;
  rejected: number;
  in_progress: number;
  failed: number;
  terminal_completed: number;
  terminal_denominator: number;
  completion_rate: number | null;
  completion_sufficient: boolean;
  median_completion_seconds: number | null;
};

export type Bottleneck = {
  workflow_uuid: string;
  workflow_name: string;
  node_id: string;
  node_label: string | null;
  decisions: number;
  average_seconds: number | null;
  median_seconds: number | null;
  sufficient: boolean;
  open_count: number;
};

export type AuditEvent = {
  uuid: string;
  event_type: string;
  created_at: string;
  node_id: string | null;
  node_label: string | null;
  instance_uuid: string | null;
  workflow_uuid: string | null;
  workflow_name: string | null;
  actor_name: string | null;
  payload: Record<string, unknown>;
};

export type AuditPage = {
  timezone: "UTC";
  scope: AnalyticsScope;
  scope_label: string;
  items: AuditEvent[];
  current_page: number;
  last_page: number;
  total: number;
};

export type AnalyticsQuery = {
  from: string;
  to: string;
  workflow?: string;
  status?: string;
};

const STATUS_COLOR: Record<InstanceStatus, string> = {
  completed: "var(--status-success)",
  rejected: "var(--destructive)",
  failed: "var(--destructive)",
  awaiting_approval: "var(--status-warning)",
  awaiting_revision: "var(--status-warning)",
  awaiting_assignment: "var(--status-warning)",
  awaiting_delay: "var(--status-neutral)",
  running: "var(--primary)",
};

export function statusColor(status: InstanceStatus): string {
  return STATUS_COLOR[status];
}

export function utcDay(offset: number): string {
  const date = new Date();
  date.setUTCDate(date.getUTCDate() + offset);

  return date.toISOString().slice(0, 10);
}

export function formatRate(rate: number | null, sufficient: boolean): string {
  if (!sufficient || rate === null) {
    return "Not enough completed requests";
  }

  return new Intl.NumberFormat(undefined, { style: "percent", maximumFractionDigits: 1 }).format(rate);
}

export function formatSeconds(seconds: number | null, sufficient: boolean): string {
  if (!sufficient || seconds === null) {
    return "Not enough completed requests";
  }

  if (seconds < 60) {
    return `${seconds}s`;
  }

  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);

  if (hours === 0) {
    return `${minutes}m`;
  }

  return minutes === 0 ? `${hours}h` : `${hours}h ${minutes}m`;
}

export function analyticsPath(organizationId: string, params: AnalyticsQuery): string {
  return `/api/v1/organizations/${organizationId}/analytics?${query(params)}`;
}

export function getAnalytics(organizationId: string, params: AnalyticsQuery): Promise<AnalyticsReport> {
  return api<AnalyticsReport>(analyticsPath(organizationId, params));
}

export function getAudit(
  organizationId: string,
  params: AnalyticsQuery & { page: number; event_type?: string; actor?: string; instance?: string },
): Promise<AuditPage> {
  return api<AuditPage>(`/api/v1/organizations/${organizationId}/audit?${query(params)}`);
}

export function exportPath(organizationId: string, kind: "analytics" | "audit", params: Record<string, string | number | undefined>): string {
  const suffix = kind === "analytics" ? "analytics/export" : "audit/export";

  return `${apiOrigin()}/api/v1/organizations/${organizationId}/${suffix}?${query(params)}`;
}

export async function downloadExport(
  organizationId: string,
  kind: "analytics" | "audit",
  params: Record<string, string | number | undefined>,
): Promise<void> {
  const response = await fetch(exportPath(organizationId, kind, params), {
    credentials: "include",
    headers: { Accept: "text/csv" },
  });

  if (!response.ok) {
    throw new Error("The export could not be downloaded.");
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = kind === "analytics" ? "flowmv-analytics.csv" : "flowmv-audit.csv";
  link.click();
  URL.revokeObjectURL(url);
}

function apiOrigin(): string {
  return (process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000").replace(/\/$/, "");
}

function query(params: Record<string, string | number | undefined>): string {
  const search = new URLSearchParams();

  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== "") {
      search.set(key, String(value));
    }
  }

  return search.toString();
}
