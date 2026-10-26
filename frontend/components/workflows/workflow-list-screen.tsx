"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { GitBranch } from "lucide-react";
import { CreateWorkflowDialog } from "@/components/workflows/create-workflow-dialog";
import { EmptyState } from "@/components/patterns/empty-state";
import { ErrorState, describeApiError } from "@/components/patterns/error-state";
import { LoadingState } from "@/components/patterns/loading-state";
import { PageHeader } from "@/components/patterns/page-header";
import { StatusBadge } from "@/components/patterns/status-badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { WorkflowList, WorkflowSummary } from "@/lib/workflows/types";

export function WorkflowListScreen() {
  const { user } = useAuth();
  const organizationId = user?.current_organization?.uuid;
  const role = user?.current_organization?.role;
  const [items, setItems] = useState<WorkflowSummary[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("all");
  const [loadedKey, setLoadedKey] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const canCreate = role === "admin" || role === "manager";

  useEffect(() => {
    if (!organizationId) {
      return;
    }

    let active = true;
    const handle = window.setTimeout(() => {
      const params = new URLSearchParams({ page: String(page) });

      if (search.trim()) {
        params.set("search", search.trim());
      }

      if (role !== "employee" && status !== "all") {
        params.set("status", status);
      }

      void api<WorkflowList>(`/api/v1/organizations/${organizationId}/workflows?${params.toString()}`)
        .then((result) => {
          if (!active) {
            return;
          }

          setItems(result.items);
          setTotal(result.total);
          setLastPage(result.last_page);
          setError(null);
          setLoadedKey(organizationId);
        })
        .catch((caught) => {
          if (!active) {
            return;
          }

          setItems([]);
          setError(describeApiError(caught, "Could not load workflows.").description);
          setLoadedKey(organizationId);
        });
    }, 200);

    return () => {
      active = false;
      window.clearTimeout(handle);
    };
  }, [organizationId, page, role, search, status]);

  if (!organizationId || loadedKey !== organizationId) {
    return <LoadingState label="Loading workflows" />;
  }

  if (error) {
    return <ErrorState title="Workflows unavailable" description={error} />;
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Workflows"
        description="Design approval workflows for this workspace. Published versions stay available while a newer draft is in progress."
        actions={canCreate ? <CreateWorkflowDialog organizationId={organizationId} /> : null}
      />
      <div className="flex flex-col gap-2 sm:flex-row">
        <Input
          value={search}
          onChange={(event) => {
            setPage(1);
            setSearch(event.target.value);
          }}
          placeholder="Search by name"
          aria-label="Search workflows"
          className="h-10 sm:max-w-xs"
        />
        {role === "employee" ? null : (
          <Select
            value={status}
            onValueChange={(value) => {
              setPage(1);
              setStatus(value);
            }}
          >
            <SelectTrigger className="h-10 w-full sm:w-48" aria-label="Filter by status">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All statuses</SelectItem>
              <SelectItem value="draft">Draft</SelectItem>
              <SelectItem value="published">Published</SelectItem>
              <SelectItem value="archived">Archived</SelectItem>
            </SelectContent>
          </Select>
        )}
      </div>
      {items.length === 0 ? (
        <EmptyState
          icon={<GitBranch />}
          title="No workflows"
          description={canCreate ? "Create a workflow to design its form and approval path." : "Published workflows will appear here."}
        />
      ) : (
        <>
          <div className="hidden md:block">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Name</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Version</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {items.map((workflow) => (
                  <TableRow key={workflow.uuid}>
                    <TableCell>
                      <Link href={`/workflows/${workflow.uuid}`} className="font-medium underline-offset-4 hover:underline">
                        {workflow.name}
                      </Link>
                      {workflow.description ? <p className="mt-1 text-sm text-muted-foreground">{workflow.description}</p> : null}
                    </TableCell>
                    <TableCell>
                      <StatusBadge>{statusLabel(workflow)}</StatusBadge>
                    </TableCell>
                    <TableCell>{workflow.current_version_number ?? "Not published"}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
          <ul className="flex flex-col gap-3 md:hidden">
            {items.map((workflow) => (
              <li key={workflow.uuid} className="rounded-xl bg-card p-4 ring-1 ring-foreground/10">
                <Link href={`/workflows/${workflow.uuid}`} className="font-medium">
                  {workflow.name}
                </Link>
                <div className="mt-2">
                  <StatusBadge>{statusLabel(workflow)}</StatusBadge>
                </div>
              </li>
            ))}
          </ul>
          {lastPage > 1 ? (
            <div className="flex items-center justify-between gap-2">
              <p className="text-sm text-muted-foreground">
                {total} workflows
              </p>
              <div className="flex gap-2">
                <Button type="button" variant="outline" disabled={page <= 1} onClick={() => setPage((current) => current - 1)}>
                  Previous
                </Button>
                <Button type="button" variant="outline" disabled={page >= lastPage} onClick={() => setPage((current) => current + 1)}>
                  Next
                </Button>
              </div>
            </div>
          ) : null}
        </>
      )}
    </div>
  );
}

function statusLabel(workflow: WorkflowSummary): string {
  if (workflow.status === "published" && workflow.has_unpublished_changes) {
    return "Published, unpublished changes";
  }

  if (workflow.status === "published") {
    return "Published";
  }

  if (workflow.status === "archived") {
    return "Archived";
  }

  return "Draft";
}
