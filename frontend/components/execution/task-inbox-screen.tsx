"use client";

import { useEffect, useMemo, useState } from "react";
import { IntentLink as Link } from "@/components/shell/intent-link";
import { Inbox, ListTodo, RotateCcw, ShieldCheck, UserRoundCheck } from "lucide-react";
import { TaskDetail } from "@/components/execution/task-detail";
import { ListPager } from "@/components/execution/list-pager";
import { EmptyState } from "@/components/patterns/empty-state";
import { ErrorState, describeApiError } from "@/components/patterns/error-state";
import { LoadingState } from "@/components/patterns/loading-state";
import { FilterBar } from "@/components/patterns/filter-bar";
import { PageHeader } from "@/components/patterns/page-header";
import { StatCard } from "@/components/patterns/stat-card";
import { StatusBadge } from "@/components/patterns/status-badge";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { useAuth } from "@/lib/auth";
import { formatWhen, listTasks, taskTypeLabel, type TaskStatus, type WorkflowTask } from "@/lib/execution";

type SortOrder = "newest" | "oldest";

export function TaskInboxScreen() {
  const { user } = useAuth();
  const organizationId = user?.current_organization?.uuid;
  const [status, setStatus] = useState<TaskStatus>("open");
  const [search, setSearch] = useState("");
  const [sort, setSort] = useState<SortOrder>("newest");
  const [page, setPage] = useState(1);
  const [items, setItems] = useState<WorkflowTask[]>([]);
  const [total, setTotal] = useState(0);
  const [lastPage, setLastPage] = useState(1);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [loadedKey, setLoadedKey] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  const [counts, setCounts] = useState<{ open: number | null; completed: number | null }>({ open: null, completed: null });
  const requestKey = organizationId ? `${organizationId}:${status}:${page}:${search.trim()}:${reloadKey}` : null;

  useEffect(() => {
    if (!organizationId) {
      return;
    }

    let active = true;
    const handle = window.setTimeout(() => {
      void listTasks(organizationId, { page, status, search: search.trim() || undefined })
        .then((result) => {
          if (!active) {
            return;
          }

          setItems(result.items);
          setTotal(result.total);
          setLastPage(result.last_page);
          setError(null);
          setLoadedKey(`${organizationId}:${status}:${page}:${search.trim()}:${reloadKey}`);
        })
        .catch((caught: unknown) => {
          if (!active) {
            return;
          }

          setItems([]);
          setError(describeApiError(caught, "Could not load tasks.").description);
          setLoadedKey(`${organizationId}:${status}:${page}:${search.trim()}:${reloadKey}`);
        });
    }, 200);

    return () => {
      active = false;
      window.clearTimeout(handle);
    };
  }, [organizationId, page, reloadKey, search, status]);

  useEffect(() => {
    if (!organizationId) {
      return;
    }

    let active = true;
    void Promise.all([
      listTasks(organizationId, { page: 1, status: "open" }),
      listTasks(organizationId, { page: 1, status: "completed" }),
    ])
      .then(([open, completed]) => {
        if (!active) {
          return;
        }

        setCounts({ open: open.total, completed: completed.total });
      })
      .catch(() => undefined);

    return () => {
      active = false;
    };
  }, [organizationId, reloadKey]);

  const selected = items.find((item) => item.uuid === selectedId) ?? null;
  const loading = loadedKey !== requestKey;
  const revisionsInView = items.filter((task) => task.task_type === "revision").length;
  const visible = useMemo(() => sortTasks(items, sort), [items, sort]);

  if (!organizationId || !loadedKey?.startsWith(`${organizationId}:`)) {
    return <LoadingState label="Loading tasks" />;
  }

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        eyebrow="Work"
        title="My Tasks"
        description="Review approvals, complete assigned work, and respond to revision requests."
      />
      <div className={`grid gap-3 sm:grid-cols-2 ${revisionsInView > 0 ? "xl:grid-cols-3" : ""}`}>
        <StatCard label="Needs action" value={counts.open === null ? "—" : String(counts.open)} detail="Open tasks assigned to you" />
        <StatCard label="Completed" value={counts.completed === null ? "—" : String(counts.completed)} detail="Tasks you can still view" />
        {revisionsInView > 0 ? (
          <StatCard label="Revisions in view" value={String(revisionsInView)} detail="On this page, not the whole workspace" />
        ) : null}
      </div>
      <FilterBar label="Task filters">
        <Input
          value={search}
          onChange={(event) => {
            setPage(1);
            setSearch(event.target.value);
          }}
          placeholder="Search by workflow"
          aria-label="Search tasks"
          className="h-9 sm:max-w-xs"
        />
        <Tabs
          value={status}
          onValueChange={(value) => {
            setStatus(value as TaskStatus);
            setPage(1);
            setSelectedId(null);
          }}
          className="gap-0"
        >
          <TabsList className="h-9 w-full sm:w-auto">
            <TabsTrigger value="open">Needs action</TabsTrigger>
            <TabsTrigger value="completed">Completed</TabsTrigger>
          </TabsList>
        </Tabs>
        <Select value={sort} onValueChange={(value) => setSort(value as SortOrder)}>
          <SelectTrigger className="h-9 w-full sm:w-40" aria-label="Sort tasks">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="newest">Newest first</SelectItem>
            <SelectItem value="oldest">Oldest first</SelectItem>
          </SelectContent>
        </Select>
      </FilterBar>
      {loading ? <LoadingState label="Updating tasks" /> : error ? <ErrorState title="Tasks unavailable" description={error} /> : items.length === 0 ? (
        <EmptyState
          align="center"
          icon={<ListTodo />}
          title={status === "open" ? "No open tasks" : "No completed tasks"}
          description={
            status === "open"
              ? "Approvals, assignments, and revision requests for you will appear here."
              : "Completed tasks you can view will appear here."
          }
        />
      ) : (
        <div className="grid items-start gap-4 lg:grid-cols-[minmax(18rem,24rem)_minmax(0,1fr)]">
          <div className="flex min-w-0 flex-col gap-3">
            <ul className="flex flex-col gap-2">
              {visible.map((task) => {
                const name = task.instance?.workflow?.name ?? "Request";
                const active = task.uuid === selectedId;

                return (
                  <li key={task.uuid}>
                    <Link
                      href={`/tasks/${task.uuid}`}
                      className="flex flex-col gap-2 rounded-xl bg-card p-3 ring-1 ring-foreground/10 lg:hidden"
                    >
                      <TaskSummary task={task} name={name} />
                    </Link>
                    <button
                      type="button"
                      className={`hidden w-full rounded-xl p-3 text-left ring-1 outline-none focus-visible:ring-3 focus-visible:ring-ring/50 lg:flex lg:flex-col lg:gap-2 ${active ? "bg-primary/10 ring-primary" : "bg-card ring-foreground/10 hover:bg-muted/50"}`}
                      aria-pressed={active}
                      onClick={() => setSelectedId(task.uuid)}
                    >
                      <TaskSummary task={task} name={name} />
                    </button>
                  </li>
                );
              })}
            </ul>
            <ListPager page={page} lastPage={lastPage} total={total} label="tasks" onPage={setPage} />
          </div>
          <div className="hidden min-w-0 lg:block">
            {selected ? (
              <div className="rounded-xl bg-card p-4 ring-1 ring-foreground/10 lg:sticky lg:top-20 lg:max-h-[calc(100dvh-6.5rem)] lg:overflow-y-auto">
                <TaskDetail
                  key={selected.uuid}
                  organizationId={organizationId}
                  task={selected}
                  onUpdated={() => setReloadKey((current) => current + 1)}
                />
              </div>
            ) : selectedId ? (
              <ErrorState
                title="This task changed"
                description="It is no longer in this list. Refresh the inbox to see the current assignment."
              />
            ) : (
              <EmptyState
                align="center"
                icon={<Inbox />}
                title="Select a task"
                description="Choose a task to review the request, submitted fields, and the decision."
              />
            )}
          </div>
        </div>
      )}
    </div>
  );
}

function TaskSummary({ task, name }: { task: WorkflowTask; name: string }) {
  const Icon = task.task_type === "approval" ? ShieldCheck : task.task_type === "revision" ? RotateCcw : UserRoundCheck;
  const type = taskTypeLabel(task.task_type);
  const step = task.node_label && task.node_label.toLowerCase() !== type.toLowerCase() ? task.node_label : null;
  const when = formatWhen(task.status === "completed" ? task.completed_at : task.assigned_at);
  const requester = task.instance?.submitted_by?.name;

  return (
    <>
      <span className="flex min-w-0 items-start justify-between gap-2">
        <span className="flex min-w-0 items-start gap-2 font-medium">
          <Icon className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
          <span className="min-w-0 text-pretty">{name}</span>
        </span>
        <StatusBadge tone={task.status === "completed" ? "success" : "attention"}>
          {task.status === "completed" ? "Completed" : "Open"}
        </StatusBadge>
      </span>
      <span className="text-sm text-muted-foreground">
        {type}{step ? ` · ${step}` : ""}
      </span>
      <span className="flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground">
        {requester ? <span>From {requester}</span> : null}
        <span>{task.status === "completed" ? "Completed" : "Received"} {when}</span>
      </span>
    </>
  );
}

function sortTasks(items: WorkflowTask[], sort: SortOrder): WorkflowTask[] {
  return [...items].sort((left, right) => {
    const leftTime = Date.parse((left.status === "completed" ? left.completed_at : left.assigned_at) ?? "") || 0;
    const rightTime = Date.parse((right.status === "completed" ? right.completed_at : right.assigned_at) ?? "") || 0;

    return sort === "newest" ? rightTime - leftTime : leftTime - rightTime;
  });
}
