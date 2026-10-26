"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { ListTodo } from "lucide-react";
import { TaskDetail } from "@/components/execution/task-detail";
import { ListPager } from "@/components/execution/list-pager";
import { EmptyState } from "@/components/patterns/empty-state";
import { ErrorState, describeApiError } from "@/components/patterns/error-state";
import { LoadingState } from "@/components/patterns/loading-state";
import { PageHeader } from "@/components/patterns/page-header";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { useAuth } from "@/lib/auth";
import { formatWhen, listTasks, type TaskStatus, type WorkflowTask } from "@/lib/execution";

export function TaskInboxScreen() {
  const { user } = useAuth();
  const organizationId = user?.current_organization?.uuid;
  const [status, setStatus] = useState<TaskStatus>("open");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [items, setItems] = useState<WorkflowTask[]>([]);
  const [total, setTotal] = useState(0);
  const [lastPage, setLastPage] = useState(1);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [loadedKey, setLoadedKey] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
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

  const selected = items.find((item) => item.uuid === selectedId) ?? null;

  if (!organizationId || loadedKey !== requestKey) {
    return <LoadingState label="Loading tasks" />;
  }

  if (error) {
    return <ErrorState title="Tasks unavailable" description={error} />;
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="My Tasks"
        description="Open approvals and revisions assigned to you, plus completed tasks you are allowed to see."
      />
      <Tabs
        value={status}
        onValueChange={(value) => {
          setStatus(value as TaskStatus);
          setPage(1);
          setSelectedId(null);
        }}
      >
        <TabsList className="max-w-md">
          <TabsTrigger value="open">Open</TabsTrigger>
          <TabsTrigger value="completed">Completed</TabsTrigger>
        </TabsList>
      </Tabs>
      <Input
        value={search}
        onChange={(event) => {
          setPage(1);
          setSearch(event.target.value);
        }}
        placeholder="Search by workflow"
        aria-label="Search tasks"
        className="h-10 sm:max-w-xs"
      />
      {items.length === 0 ? (
        <EmptyState
          icon={<ListTodo />}
          title={status === "open" ? "No open tasks" : "No completed tasks"}
          description={
            status === "open"
              ? "Approvals and revisions for you will appear here."
              : "Completed tasks you can view will appear here."
          }
        />
      ) : (
        <div className="grid gap-4 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
          <div className="flex flex-col gap-3">
            <ul className="flex flex-col gap-3">
              {items.map((task) => {
                const name = task.instance?.workflow?.name ?? "Request";
                const active = task.uuid === selectedId;

                return (
                  <li key={task.uuid}>
                    <Link
                      href={`/tasks/${task.uuid}`}
                      className="flex flex-col gap-1 rounded-xl bg-card p-4 ring-1 ring-foreground/10 lg:hidden"
                    >
                      <TaskSummary task={task} name={name} />
                    </Link>
                    <button
                      type="button"
                      className={`hidden w-full rounded-xl bg-card p-4 text-left ring-1 lg:flex lg:flex-col lg:gap-1 ${active ? "ring-ring" : "ring-foreground/10"}`}
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
              <div className="rounded-xl bg-card p-4 ring-1 ring-foreground/10">
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
              <EmptyState title="Select a task" description="The request, submitted fields, and actions open here." />
            )}
          </div>
        </div>
      )}
    </div>
  );
}

function TaskSummary({ task, name }: { task: WorkflowTask; name: string }) {
  return (
    <>
      <span className="font-medium">{name}</span>
      <span className="text-sm text-muted-foreground">
        {task.task_type === "approval" ? "Approval" : "Revision"} · {task.node_label ?? "Current step"} ·{" "}
        {formatWhen(task.assigned_at)}
      </span>
    </>
  );
}
