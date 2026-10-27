"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { DynamicForm } from "@/components/execution/dynamic-form";
import { ErrorState, describeApiError } from "@/components/patterns/error-state";
import { LoadingState } from "@/components/patterns/loading-state";
import { PageHeader } from "@/components/patterns/page-header";
import { Button } from "@/components/ui/button";
import { ArrowRight, Search, FileText, ArrowLeft, ClipboardList } from "lucide-react";
import { Input } from "@/components/ui/input";
import { ListPager } from "@/components/execution/list-pager";
import { EmptyState } from "@/components/patterns/empty-state";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import {
  IdempotencyKeys,
  listPublishedWorkflows,
  stableJson,
  startInstance,
  toSubmissionData,
  type SubmissionValues,
} from "@/lib/execution";
import type { FormDefinition, WorkflowDetail, WorkflowSummary } from "@/lib/workflows/types";

export function RequestCreateScreen() {
  const router = useRouter();
  const { user } = useAuth();
  const organizationId = user?.current_organization?.uuid;
  const keys = useRef(new IdempotencyKeys());
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [selectedWorkflow, setSelectedWorkflow] = useState<WorkflowSummary | null>(null);
  const [workflows, setWorkflows] = useState<WorkflowSummary[]>([]);
  const [workflowId, setWorkflowId] = useState<string>("");
  const [definition, setDefinition] = useState<FormDefinition | null>(null);
  const [loadedWorkflowId, setLoadedWorkflowId] = useState<string | null>(null);
  const [version, setVersion] = useState<number | null>(null);
  const [loadedKey, setLoadedKey] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [banner, setBanner] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  const catalogKey = `${organizationId}:${page}:${search.trim()}`;

  useEffect(() => {
    if (!organizationId) {
      return;
    }

    let active = true;

    const timer = window.setTimeout(() => {
    void listPublishedWorkflows(organizationId, search.trim(), page)
      .then((result) => {
        if (!active) {
          return;
        }

        setWorkflows(result.items);
        setLastPage(result.last_page);
        setTotal(result.total);
        setError(null);
        setLoadedKey(catalogKey);
      })
      .catch((caught: unknown) => {
        if (!active) {
          return;
        }

        setWorkflows([]);
        setError(describeApiError(caught, "Could not load published workflows.").description);
        setLoadedKey(catalogKey);
      });

    }, 200);
    return () => {
      active = false;
      window.clearTimeout(timer);
    };
  }, [organizationId, page, search, catalogKey]);

  useEffect(() => {
    if (!organizationId || !workflowId) {
      return;
    }

    let active = true;

    void api<WorkflowDetail>(`/api/v1/organizations/${organizationId}/workflows/${workflowId}`)
      .then((workflow) => {
        if (!active) {
          return;
        }

        if (!workflow.published) {
          setDefinition(null);
          setBanner("This service is no longer available. Choose another service or try again later.");
          setLoadedWorkflowId(workflowId);
          return;
        }
        setDefinition(workflow.published.form_definition);
        setVersion(workflow.published?.version_number ?? workflow.current_version_number);
        setLoadedWorkflowId(workflowId);
        setBanner(null);
      })
      .catch((caught: unknown) => {
        if (!active) {
          return;
        }

        setDefinition(null);
        setLoadedWorkflowId(workflowId);
        setBanner(describeApiError(caught, "Could not load that workflow.").description);
      });

    return () => {
      active = false;
    };
  }, [organizationId, workflowId]);

  if (!organizationId || !loadedKey?.startsWith(`${organizationId}:`)) {
    return <LoadingState label="Loading workflows" />;
  }

  if (error) {
    return <ErrorState title="Workflows unavailable" description={error} />;
  }

  async function submit(values: SubmissionValues) {
    if (!organizationId || !workflowId || !definition) {
      return;
    }

    const data = toSubmissionData(definition, values);
    const path = `/api/v1/organizations/${organizationId}/workflows/${workflowId}/instances`;
    const idempotencyKey = keys.current.for(`POST ${path} ${stableJson({ data })}`);
    setPending(true);
    setBanner(null);

    try {
      const instance = await startInstance(organizationId, workflowId, data, idempotencyKey);
      router.push(`/requests/${instance.uuid}`);
    } catch (caught) {
      if (caught instanceof ApiError && caught.status === 422) {
        setBanner(caught.message);
        throw caught;
      }

      if (caught instanceof ApiError && caught.status === 0) {
        setBanner("Could not reach FlowMV. Try again safely; your request will not be duplicated.");
        return;
      }

      setBanner(caught instanceof ApiError ? caught.message : "Could not start this request.");
    } finally {
      setPending(false);
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="New request"
        description="Find a workplace service, send your request, and track what happens next."
        actions={
          <Button variant="outline" className="h-10" asChild>
            <Link href="/requests">Back to requests</Link>
          </Button>
        }
      />
      {!workflowId ? (
        <>
          <div className="relative max-w-lg"><Search className="absolute top-3 left-3 size-4 text-muted-foreground" aria-hidden="true" /><Input className="h-10 pl-10" aria-label="Search services" placeholder="What do you need help with?" value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} /></div>
          {loadedKey !== catalogKey ? <LoadingState label="Finding services" /> : error ? <ErrorState title="Services unavailable" description={error} /> : workflows.length === 0 ? <EmptyState icon={<ClipboardList />} title={search ? "No matching services" : "No services available yet"} description={search ? "Try a different name or clear your search." : "Your workspace’s published services will appear here. Contact your workspace administrator if you need help."} /> : (
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
              {workflows.map((workflow) => (
                <article key={workflow.uuid} className="flex flex-col gap-4 rounded-2xl border bg-card p-6 shadow-sm transition-shadow hover:shadow-md">
                  <span className="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary"><FileText className="size-5" aria-hidden="true" /></span>
                  <div className="flex-1"><h2 className="font-semibold">{workflow.name}</h2>{workflow.description ? <p className="mt-2 text-sm leading-6 text-muted-foreground">{workflow.description}</p> : <p className="mt-2 text-sm leading-6 text-muted-foreground">Complete the form to start this request.</p>}</div>
                  <Button variant="outline" className="justify-between" onClick={() => { setSelectedWorkflow(workflow); setWorkflowId(workflow.uuid); setDefinition(null); setLoadedWorkflowId(null); setBanner(null); }}>Start request<ArrowRight className="size-4" aria-hidden="true" /></Button>
                </article>
              ))}
            </div>
          )}
          {loadedKey === catalogKey ? <ListPager page={page} lastPage={lastPage} total={total} label="services" onPage={setPage} /> : null}
        </>
      ) : (
        <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(16rem,1fr)]">
          <section className="rounded-2xl border bg-card p-5 shadow-sm sm:p-8">
            <Button variant="ghost" size="sm" className="mb-5" disabled={pending} onClick={() => { setWorkflowId(""); setDefinition(null); setBanner(null); }}><ArrowLeft className="size-4" aria-hidden="true" />Choose another service</Button>
            <h2 className="text-xl font-semibold tracking-tight">{selectedWorkflow?.name ?? "Request details"}</h2>
            {selectedWorkflow?.description ? <p className="mt-2 text-sm leading-6 text-muted-foreground">{selectedWorkflow.description}</p> : null}
            <div className="mt-6">
              {definition && loadedWorkflowId === workflowId ? <DynamicForm key={`${workflowId}:${version ?? 0}`} definition={definition} submitLabel="Submit request" confirmTitle="Submit this request?" confirmDescription={`Your answers will start “${selectedWorkflow?.name ?? "this request"}”. Check the details before continuing.`} pending={pending} banner={banner} onSubmit={submit} /> : loadedWorkflowId === workflowId && banner ? <ErrorState title="Form unavailable" description={banner} /> : <LoadingState label="Loading form" />}
            </div>
          </section>
          <aside className="rounded-xl border bg-card p-5"><h2 className="flex items-center gap-2 text-sm font-semibold"><ClipboardList className="size-4 text-primary" aria-hidden="true" />Before you submit</h2><ol className="mt-4 space-y-3 text-sm leading-6 text-muted-foreground"><li>1. Complete the required fields.</li><li>2. Review your answers and submit.</li><li>3. Track the next step in Requests. If changes are needed, they’ll appear in My Tasks.</li></ol></aside>
        </div>
      )}
    </div>
  );
}
