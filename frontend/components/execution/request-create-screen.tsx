"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { DynamicForm } from "@/components/execution/dynamic-form";
import { ErrorState, describeApiError } from "@/components/patterns/error-state";
import { LoadingState } from "@/components/patterns/loading-state";
import { PageHeader } from "@/components/patterns/page-header";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
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
  const [workflows, setWorkflows] = useState<WorkflowSummary[]>([]);
  const [workflowId, setWorkflowId] = useState<string>("");
  const [definition, setDefinition] = useState<FormDefinition | null>(null);
  const [loadedWorkflowId, setLoadedWorkflowId] = useState<string | null>(null);
  const [version, setVersion] = useState<number | null>(null);
  const [loadedKey, setLoadedKey] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [banner, setBanner] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  useEffect(() => {
    if (!organizationId) {
      return;
    }

    let active = true;

    void listPublishedWorkflows(organizationId, "", 1)
      .then((result) => {
        if (!active) {
          return;
        }

        setWorkflows(result.items);
        setError(null);
        setLoadedKey(organizationId);
      })
      .catch((caught: unknown) => {
        if (!active) {
          return;
        }

        setWorkflows([]);
        setError(describeApiError(caught, "Could not load published workflows.").description);
        setLoadedKey(organizationId);
      });

    return () => {
      active = false;
    };
  }, [organizationId]);

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

        setDefinition(workflow.published?.form_definition ?? { fields: [] });
        setVersion(workflow.published?.version_number ?? workflow.current_version_number);
        setLoadedWorkflowId(workflowId);
        setBanner(null);
      })
      .catch((caught: unknown) => {
        if (!active) {
          return;
        }

        setDefinition(null);
        setBanner(describeApiError(caught, "Could not load that workflow.").description);
      });

    return () => {
      active = false;
    };
  }, [organizationId, workflowId]);

  if (!organizationId || loadedKey !== organizationId) {
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
        setBanner("Could not reach FlowMV. Retrying will reuse the same idempotency key.");
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
        description="Choose a published workflow. The form comes from that published version."
        actions={
          <Button variant="outline" className="h-10" asChild>
            <Link href="/requests">Back to requests</Link>
          </Button>
        }
      />
      {workflows.length === 0 ? (
        <ErrorState title="No published workflows" description="Publish a workflow before starting a request." />
      ) : (
        <div className="flex max-w-xl flex-col gap-5">
          <div className="flex flex-col gap-2">
            <label htmlFor="workflow" className="text-sm font-medium">
              Workflow
            </label>
            <Select
              value={workflowId}
              onValueChange={(value) => {
                setWorkflowId(value);
                setDefinition(null);
              }}
            >
              <SelectTrigger id="workflow" className="w-full">
                <SelectValue placeholder="Choose a workflow" />
              </SelectTrigger>
              <SelectContent>
                {workflows.map((workflow) => (
                  <SelectItem key={workflow.uuid} value={workflow.uuid}>
                    {workflow.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {version ? <p className="text-sm text-muted-foreground">Version {version}</p> : null}
          </div>
          {definition && loadedWorkflowId === workflowId ? (
            <DynamicForm
              key={`${workflowId}:${version ?? 0}`}
              definition={definition}
              submitLabel="Submit request"
              pending={pending}
              banner={banner}
              onSubmit={submit}
            />
          ) : workflowId ? (
            <LoadingState label="Loading form" />
          ) : null}
        </div>
      )}
    </div>
  );
}
