"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { TaskDetail } from "@/components/execution/task-detail";
import { ErrorState, describeApiError } from "@/components/patterns/error-state";
import { LoadingState } from "@/components/patterns/loading-state";
import { PageHeader } from "@/components/patterns/page-header";
import { Button } from "@/components/ui/button";
import { useAuth } from "@/lib/auth";
import { getTask, type WorkflowTask } from "@/lib/execution";

export function TaskPageScreen() {
  const params = useParams<{ taskId: string }>();
  const { user } = useAuth();
  const organizationId = user?.current_organization?.uuid;
  const [task, setTask] = useState<WorkflowTask | null>(null);
  const [loadedKey, setLoadedKey] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  const requestKey = organizationId ? `${organizationId}:${params.taskId}:${reloadKey}` : null;

  useEffect(() => {
    if (!organizationId) {
      return;
    }

    let active = true;

    void getTask(organizationId, params.taskId)
      .then((result) => {
        if (!active) {
          return;
        }

        setTask(result);
        setError(null);
        setLoadedKey(`${organizationId}:${params.taskId}:${reloadKey}`);
      })
      .catch((caught: unknown) => {
        if (!active) {
          return;
        }

        setTask(null);
        setError(describeApiError(caught, "Could not load this task.").description);
        setLoadedKey(`${organizationId}:${params.taskId}:${reloadKey}`);
      });

    return () => {
      active = false;
    };
  }, [organizationId, params.taskId, reloadKey]);

  if (!requestKey || loadedKey !== requestKey) {
    return <LoadingState label="Loading task" />;
  }

  if (error || !task || !organizationId) {
    return <ErrorState title="Task unavailable" description={error ?? "That task is not available."} />;
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Task"
        description="Review the request and record the decision you are allowed to make."
        actions={
          <Button variant="outline" className="h-10" asChild>
            <Link href="/tasks">All tasks</Link>
          </Button>
        }
      />
      <div className="rounded-xl bg-card p-4 ring-1 ring-foreground/10">
        <TaskDetail organizationId={organizationId} task={task} onUpdated={() => setReloadKey((current) => current + 1)} />
      </div>
    </div>
  );
}
