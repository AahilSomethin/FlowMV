"use client";

import { useEffect, useState } from "react";
import { IntentLink as Link } from "@/components/shell/intent-link";
import { ArrowRight, BarChart3, GitBranch, Inbox, ListTodo } from "lucide-react";
import { EmptyState } from "@/components/patterns/empty-state";
import { ErrorState, describeApiError } from "@/components/patterns/error-state";
import { PageHeader } from "@/components/patterns/page-header";
import { SectionCard } from "@/components/patterns/section-card";
import { StatCard } from "@/components/patterns/stat-card";
import { StatusBadge } from "@/components/patterns/status-badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { formatRate, formatSeconds, getAnalytics, utcDay } from "@/lib/analytics";
import { useAuth } from "@/lib/auth";
import {
  formatWhen,
  instanceStatusLabel,
  instanceStatusTone,
  listInstances,
  listTasks,
  taskTypeLabel,
  type WorkflowInstance,
  type WorkflowTask,
} from "@/lib/execution";

export function DashboardScreen() {
  const { user } = useAuth();
  const current = user?.current_organization;
  const organizationId = current?.uuid;

  if (!user) {
    return null;
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        eyebrow={current?.name ?? "Workspace"}
        title="Overview"
        description="What needs you now. Performance, trends, and bottlenecks live in Analytics."
        actions={
          <Button asChild>
            <Link href="/requests/new">Start a request<ArrowRight aria-hidden="true" /></Link>
          </Button>
        }
      />
      {organizationId ? <WorkspacePulse key={organizationId} organizationId={organizationId} /> : null}
      <div className="grid gap-4 lg:grid-cols-5">
        <div className="lg:col-span-3">
          {organizationId ? <OpenTasks key={organizationId} organizationId={organizationId} /> : null}
        </div>
        <div className="flex flex-col gap-4 lg:col-span-2">
          {organizationId ? <RecentRequests key={organizationId} organizationId={organizationId} /> : null}
          <QuickActions employee={current?.role === "employee"} />
        </div>
      </div>
    </div>
  );
}

function WorkspacePulse({ organizationId }: { organizationId: string }) {
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [message, setMessage] = useState("");
  const [pulse, setPulse] = useState<{ total: number; completion: string; wait: string; open: number; scope: string } | null>(null);

  useEffect(() => {
    let active = true;
    void getAnalytics(organizationId, { from: utcDay(-29), to: utcDay(0) })
      .then((report) => {
        if (!active) return;
        setPulse({
          total: report.cohort.total,
          completion: formatRate(report.completion.rate, report.completion.sufficient),
          wait: formatSeconds(report.approval_wait.median_seconds, report.approval_wait.sufficient),
          open: report.approval_wait.open_count,
          scope: report.scope_label,
        });
        setState("ready");
      })
      .catch((caught: unknown) => {
        if (!active) return;
        setMessage(describeApiError(caught, "Could not load the workspace pulse.").description);
        setState("error");
      });

    return () => {
      active = false;
    };
  }, [organizationId]);

  if (state === "loading") {
    return <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" role="status" aria-label="Loading workspace pulse"><Skeleton className="h-28" /><Skeleton className="h-28" /><Skeleton className="h-28" /><Skeleton className="h-28" /></div>;
  }

  if (state === "error" || !pulse) {
    return <ErrorState title="Workspace pulse unavailable" description={message} />;
  }

  return (
    <section className="flex flex-col gap-3" aria-label="Last 30 days">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <div>
          <h2 className="text-sm font-medium">Last 30 days</h2>
          <p className="text-sm text-muted-foreground">{pulse.scope}</p>
        </div>
        <Link href="/analytics" className="text-sm font-medium text-primary underline-offset-4 hover:underline">Open analytics</Link>
      </div>
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Requests started" value={String(pulse.total)} detail="UTC window, current scope" />
        <StatCard label="Completion rate" value={pulse.completion} detail="Finished requests only" />
        <StatCard label="Median approval wait" value={pulse.wait} detail="Hidden when the sample is too small" />
        <StatCard label="Approvals open now" value={String(pulse.open)} detail="Not limited to the 30-day window" />
      </div>
    </section>
  );
}

function OpenTasks({ organizationId }: { organizationId: string }) {
  const [tasks, setTasks] = useState<WorkflowTask[] | null>(null);
  const [total, setTotal] = useState(0);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    void listTasks(organizationId, { page: 1, status: "open" })
      .then((page) => {
        if (!active) return;
        setTasks(page.items.slice(0, 5));
        setTotal(page.total);
      })
      .catch((caught: unknown) => {
        if (!active) return;
        setError(describeApiError(caught, "Could not load your tasks.").description);
        setTasks([]);
      });

    return () => {
      active = false;
    };
  }, [organizationId]);

  return (
    <SectionCard title="Needs your action" description={tasks ? `${total} open ${total === 1 ? "task" : "tasks"}` : "Open tasks assigned to you"} action={<Button variant="outline" size="sm" asChild><Link href="/tasks">All tasks</Link></Button>}>
      {error ? <p role="alert" className="text-sm text-destructive">{error}</p> : null}
      {!tasks ? <Skeleton className="h-36" /> : tasks.length === 0 ? (
        <EmptyState icon={<ListTodo />} title="Nothing waiting" description="When a workflow assigns you an approval, revision, or task, it shows up here." />
      ) : (
        <ul className="flex flex-col divide-y">
          {tasks.map((task) => (
            <li key={task.uuid}>
              <Link href={`/tasks/${task.uuid}`} className="flex items-start justify-between gap-3 py-3 hover:bg-surface-subtle">
                <span className="min-w-0">
                  <span className="block truncate font-medium">{task.instance?.workflow?.name ?? task.node_label ?? "Task"}</span>
                  <span className="block text-sm text-muted-foreground">{taskTypeLabel(task.task_type)} · {formatWhen(task.assigned_at)}</span>
                </span>
                <StatusBadge tone="attention">{taskTypeLabel(task.task_type)}</StatusBadge>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </SectionCard>
  );
}

function RecentRequests({ organizationId }: { organizationId: string }) {
  const [items, setItems] = useState<WorkflowInstance[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    void listInstances(organizationId, { page: 1 })
      .then((page) => {
        if (!active) return;
        setItems(page.items.slice(0, 5));
      })
      .catch((caught: unknown) => {
        if (!active) return;
        setError(describeApiError(caught, "Could not load recent requests.").description);
        setItems([]);
      });

    return () => {
      active = false;
    };
  }, [organizationId]);

  return (
    <SectionCard title="Recent requests" description="Latest submissions you can open" action={<Button variant="ghost" size="sm" asChild><Link href="/requests">View all</Link></Button>}>
      {error ? <p role="alert" className="text-sm text-destructive">{error}</p> : null}
      {!items ? <Skeleton className="h-36" /> : items.length === 0 ? (
        <EmptyState icon={<Inbox />} title="No requests yet" description="Start a request when a published workflow is available." action={<Button size="sm" asChild><Link href="/requests/new">Browse services</Link></Button>} />
      ) : (
        <ul className="flex flex-col divide-y">
          {items.map((item) => (
            <li key={item.uuid} className="flex items-center justify-between gap-3 py-3">
              <Link href={`/requests/${item.uuid}`} className="min-w-0 truncate text-sm font-medium underline-offset-4 hover:underline">{item.workflow?.name ?? "Request"}</Link>
              <StatusBadge tone={instanceStatusTone(item.status)}>{instanceStatusLabel(item.status)}</StatusBadge>
            </li>
          ))}
        </ul>
      )}
    </SectionCard>
  );
}

function QuickActions({ employee }: { employee: boolean }) {
  const actions = [
    { href: "/requests/new", label: "Start a request", icon: Inbox },
    { href: "/tasks", label: "Open My Tasks", icon: ListTodo },
    ...(employee ? [] : [{ href: "/workflows", label: "Design workflows", icon: GitBranch }]),
    { href: "/analytics", label: "Review analytics", icon: BarChart3 },
  ];

  return (
    <section className="rounded-xl bg-card p-4 ring-1 ring-foreground/10">
      <h2 className="text-sm font-medium">Jump back in</h2>
      <div className="mt-3 grid grid-cols-2 gap-2">
        {actions.map((item) => {
          const Icon = item.icon;
          return (
            <Link key={item.href} href={item.href} className="flex items-center gap-2 rounded-lg bg-surface-subtle px-3 py-3 text-sm font-medium hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring">
              <Icon className="size-4 text-primary" aria-hidden="true" />
              {item.label}
            </Link>
          );
        })}
      </div>
    </section>
  );
}
