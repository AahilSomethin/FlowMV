"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Inbox } from "lucide-react";
import { InstanceStatusBadge } from "@/components/execution/status-badge";
import { ListPager } from "@/components/execution/list-pager";
import { EmptyState } from "@/components/patterns/empty-state";
import { ErrorState, describeApiError } from "@/components/patterns/error-state";
import { LoadingState } from "@/components/patterns/loading-state";
import { PageHeader } from "@/components/patterns/page-header";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { useAuth } from "@/lib/auth";
import { formatWhen, listInstances, type InstanceStatus, type WorkflowInstance } from "@/lib/execution";

const statuses: Array<{ value: "all" | InstanceStatus; label: string }> = [
  { value: "all", label: "All statuses" },
  { value: "running", label: "In progress" },
  { value: "awaiting_approval", label: "Awaiting approval" },
  { value: "awaiting_revision", label: "Needs revision" },
  { value: "awaiting_assignment", label: "Awaiting assignment" },
  { value: "awaiting_delay", label: "Waiting" },
  { value: "completed", label: "Completed" },
  { value: "rejected", label: "Rejected" },
  { value: "failed", label: "Failed" },
];

export function RequestListScreen() {
  const { user } = useAuth();
  const organizationId = user?.current_organization?.uuid;
  const [items, setItems] = useState<WorkflowInstance[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<"all" | InstanceStatus>("all");
  const workflow = useSearchParams().get("workflow") ?? "all";
  const [loadedKey, setLoadedKey] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const requestKey = organizationId ? `${organizationId}:${page}:${status}:${workflow}:${search.trim()}` : null;

  useEffect(() => {
    if (!organizationId) {
      return;
    }

    let active = true;
    const handle = window.setTimeout(() => {
      void listInstances(organizationId, {
        page,
        status: status === "all" ? undefined : status,
        search: search.trim() || undefined,
        workflow: workflow === "all" ? undefined : workflow,
      })
        .then((result) => {
          if (!active) {
            return;
          }

          setItems(result.items);
          setTotal(result.total);
          setLastPage(result.last_page);
          setError(null);
          setLoadedKey(`${organizationId}:${page}:${status}:${workflow}:${search.trim()}`);
        })
        .catch((caught: unknown) => {
          if (!active) {
            return;
          }

          setItems([]);
          setError(describeApiError(caught, "Could not load requests.").description);
          setLoadedKey(`${organizationId}:${page}:${status}:${workflow}:${search.trim()}`);
        });
    }, 200);

    return () => {
      active = false;
      window.clearTimeout(handle);
    };
  }, [organizationId, page, search, status, workflow]);

  const loading = loadedKey !== requestKey;

  if (!organizationId || !loadedKey?.startsWith(`${organizationId}:`)) {
    return <LoadingState label="Loading requests" />;
  }


  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Requests"
        description="Start a workplace request and follow its next steps. You’ll see the requests you’re authorized to access."
        actions={
          <Button className="h-10" asChild>
            <Link href="/requests/new">New request</Link>
          </Button>
        }
      />
      <div className="flex flex-col gap-2 sm:flex-row">
        <Input
          value={search}
          onChange={(event) => {
            setPage(1);
            setSearch(event.target.value);
          }}
          placeholder="Search by workflow"
          aria-label="Search requests"
          className="h-10 sm:max-w-xs"
        />
        <Select
          value={status}
          onValueChange={(value) => {
            setPage(1);
            setStatus(value as "all" | InstanceStatus);
          }}
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
      {loading ? <LoadingState label="Updating requests" /> : error ? <ErrorState title="Requests unavailable" description={error} /> : items.length === 0 ? (
        <EmptyState
          icon={<Inbox />}
          title="No requests"
          description="Start a published workflow to create a request in this workspace."
          action={
            <Button className="h-10" asChild>
              <Link href="/requests/new">New request</Link>
            </Button>
          }
        />
      ) : (
        <>
          <div className="hidden md:block">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Workflow</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Current step</TableHead>
                  <TableHead>Version</TableHead>
                  <TableHead>Submitted by</TableHead>
                  <TableHead>Started</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {items.map((item) => (
                  <TableRow key={item.uuid}>
                    <TableCell>
                      <Link href={`/requests/${item.uuid}`} className="font-medium underline-offset-4 hover:underline">
                        {item.workflow?.name ?? "Request"}
                      </Link>
                    </TableCell>
                    <TableCell>
                      <InstanceStatusBadge status={item.status} />
                    </TableCell>
                    <TableCell>{item.current_node_label ?? "Not recorded"}</TableCell>
                    <TableCell>{item.workflow_version ? `Version ${item.workflow_version.version_number}` : "Not recorded"}</TableCell>
                    <TableCell>{item.submitted_by?.name ?? "Unknown"}</TableCell>
                    <TableCell>{formatWhen(item.started_at)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
          <ul className="flex flex-col gap-3 md:hidden">
            {items.map((item) => (
              <li key={item.uuid} className="rounded-xl bg-card p-4 ring-1 ring-foreground/10">
                <Link href={`/requests/${item.uuid}`} className="font-medium">
                  {item.workflow?.name ?? "Request"}
                </Link>
                <div className="mt-2 flex flex-wrap items-center gap-2">
                  <InstanceStatusBadge status={item.status} />
                  <span className="text-sm text-muted-foreground">{item.current_node_label ?? "Not recorded"}</span>
                </div>
              </li>
            ))}
          </ul>
          <ListPager page={page} lastPage={lastPage} total={total} label="requests" onPage={setPage} />
        </>
      )}
    </div>
  );
}
