"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { WorkflowCanvas } from "@/components/workflows/workflow-canvas";
import { ErrorState, describeApiError } from "@/components/patterns/error-state";
import { LoadingState } from "@/components/patterns/loading-state";
import { PageHeader } from "@/components/patterns/page-header";
import { StatusBadge } from "@/components/patterns/status-badge";
import { TextField } from "@/components/auth/text-field";
import { Alert, AlertDescription } from "@/components/ui/alert";
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { Button } from "@/components/ui/button";
import { Field, FieldLabel } from "@/components/ui/field";
import { Textarea } from "@/components/ui/textarea";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { applyServerErrors } from "@/lib/form-errors";
import type { WorkflowDetail, WorkflowVersionSummary } from "@/lib/workflows/types";
import { visibleWorkflowGraph } from "@/lib/workflows/visible-graph";

const schema = z.object({
  name: z.string().trim().min(1, "Name is required.").max(120),
  description: z.string().max(2000),
});

type Values = z.infer<typeof schema>;

export function WorkflowDetailScreen({ workflowId }: { workflowId: string }) {
  const { user } = useAuth();
  const organizationId = user?.current_organization?.uuid;
  const [workflow, setWorkflow] = useState<WorkflowDetail | null>(null);
  const [versions, setVersions] = useState<WorkflowVersionSummary[]>([]);
  const [selectedVersion, setSelectedVersion] = useState<WorkflowVersionSummary | null>(null);
  const [loadedKey, setLoadedKey] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [archiveOpen, setArchiveOpen] = useState(false);
  const [publishOpen, setPublishOpen] = useState(false);
  const requestKey = organizationId ? `${organizationId}:${workflowId}` : null;

  useEffect(() => {
    if (!organizationId) {
      return;
    }

    let active = true;

    async function load() {
      try {
        const [next, history] = await Promise.all([
          api<WorkflowDetail>(`/api/v1/organizations/${organizationId}/workflows/${workflowId}`),
          api<WorkflowVersionSummary[]>(`/api/v1/organizations/${organizationId}/workflows/${workflowId}/versions`),
        ]);

        if (!active) {
          return;
        }

        setWorkflow(next);
        setVersions(history);
        setError(null);
        setLoadedKey(`${organizationId}:${workflowId}`);
      } catch (caught) {
        if (!active) {
          return;
        }

        setWorkflow(null);
        setError(describeApiError(caught, "Could not load this workflow.").description);
        setLoadedKey(`${organizationId}:${workflowId}`);
      }
    }

    void load();

    return () => {
      active = false;
    };
  }, [organizationId, workflowId]);

  if (!requestKey || loadedKey !== requestKey) {
    return <LoadingState label="Loading workflow" />;
  }

  if (error || !workflow || !organizationId) {
    return <ErrorState title="Workflow unavailable" description={error ?? "This workflow could not be loaded."} />;
  }

  const shown = visibleWorkflowGraph(workflow, selectedVersion?.graph_definition ?? null);

  async function archive() {
    try {
      const next = await api<WorkflowDetail>(`/api/v1/organizations/${organizationId}/workflows/${workflowId}/archive`, {
        method: "POST",
      });
      setWorkflow(next);
      setArchiveOpen(false);
      setNotice("Workflow archived. Its published snapshot remains unchanged.");
    } catch (caught) {
      setNotice(describeApiError(caught, "Could not archive this workflow.").description);
      setArchiveOpen(false);
    }
  }

  async function publish() {
    if (!workflow?.draft) {
      return;
    }

    try {
      const next = await api<WorkflowDetail>(`/api/v1/organizations/${organizationId}/workflows/${workflowId}/publish`, {
        method: "POST",
        body: JSON.stringify({ revision: workflow.draft.revision }),
      });
      const history = await api<WorkflowVersionSummary[]>(`/api/v1/organizations/${organizationId}/workflows/${workflowId}/versions`);
      setWorkflow(next);
      setVersions(history);
      setPublishOpen(false);
      setNotice(`Published version ${next.current_version_number ?? ""}.`);
    } catch (caught) {
      setPublishOpen(false);
      const details = caught instanceof ApiError ? caught.violations.map((violation) => violation.message).filter(Boolean) : [];
      setNotice(caught instanceof ApiError ? [caught.message, ...details].join(" ") : "Could not publish this workflow.");
    }
  }

  async function openVersion(version: WorkflowVersionSummary) {
    const detail = await api<WorkflowVersionSummary>(
      `/api/v1/organizations/${organizationId}/workflows/${workflowId}/versions/${version.uuid}`,
    );
    setSelectedVersion(detail);
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={workflow.name}
        description={workflow.description ?? "No description."}
        actions={
          <>
            {workflow.can_update ? (
              <Button asChild>
                <Link href={`/workflows/${workflow.uuid}/edit`}>Edit draft</Link>
              </Button>
            ) : null}
            {workflow.can_publish ? (
              <Button type="button" variant="outline" onClick={() => setPublishOpen(true)}>
                Publish
              </Button>
            ) : null}
            {workflow.can_archive ? (
              <Button type="button" variant="destructive" onClick={() => setArchiveOpen(true)}>
                Archive
              </Button>
            ) : null}
          </>
        }
      />
      <div className="flex flex-wrap gap-2">
        <StatusBadge>{workflow.status}</StatusBadge>
        {workflow.has_unpublished_changes && workflow.can_update ? <StatusBadge>Unpublished changes</StatusBadge> : null}
        <StatusBadge>{workflow.current_version_number ? `Version ${workflow.current_version_number}` : "No published version"}</StatusBadge>
      </div>
      {notice ? (
        <Alert>
          <AlertDescription>{notice}</AlertDescription>
        </Alert>
      ) : null}
      {workflow.can_update ? <MetadataForm organizationId={organizationId} workflow={workflow} onSaved={setWorkflow} /> : null}
      <p className="text-sm text-muted-foreground">
        {selectedVersion
          ? `Showing version ${selectedVersion.version_number}. Publishing uses the saved draft.`
          : workflow.draft
            ? "Showing the saved draft. Publishing uses this draft."
            : "Showing the published version."}
      </p>
      {selectedVersion ? (
        <Button type="button" variant="outline" className="self-start" onClick={() => setSelectedVersion(null)}>
          Show current graph
        </Button>
      ) : null}
      {shown ? (
        <div className="h-[min(70dvh,36rem)] min-h-64 overflow-hidden rounded-xl ring-1 ring-foreground/10">
          <WorkflowCanvas
            nodes={shown.nodes}
            edges={shown.edges}
            violations={[]}
            readOnly
            onSelect={() => undefined}
            onMove={() => undefined}
            onConnect={() => undefined}
            onDeleteNodes={() => undefined}
            onDeleteEdges={() => undefined}
            onDropNode={() => undefined}
          />
        </div>
      ) : null}
      <section className="flex flex-col gap-3">
        <h2 className="text-base font-medium">Version history</h2>
        {versions.length === 0 ? <p className="text-sm text-muted-foreground">No published versions yet.</p> : null}
        <ul className="flex flex-col gap-2">
          {versions.map((version) => (
            <li key={version.uuid}>
              <button type="button" className="text-left text-sm underline-offset-4 hover:underline" onClick={() => void openVersion(version)}>
                Version {version.version_number}
                {version.published_by ? ` · ${version.published_by.name}` : ""}
              </button>
            </li>
          ))}
        </ul>
      </section>
      <AlertDialog open={publishOpen} onOpenChange={setPublishOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Publish the saved draft?</AlertDialogTitle>
            <AlertDialogDescription>
              This creates the next immutable version from the saved draft. Unsaved editor changes are not included.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel type="button">Cancel</AlertDialogCancel>
            <Button type="button" onClick={() => void publish()}>
              Publish
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
      <AlertDialog open={archiveOpen} onOpenChange={setArchiveOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Archive this workflow?</AlertDialogTitle>
            <AlertDialogDescription>
              Archived workflows stay readable for admins and managers, and they can no longer be edited or published. Employees stop seeing them.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel type="button">Cancel</AlertDialogCancel>
            <Button type="button" variant="destructive" onClick={() => void archive()}>
              Archive
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

function MetadataForm({
  organizationId,
  workflow,
  onSaved,
}: {
  organizationId: string;
  workflow: WorkflowDetail;
  onSaved: (workflow: WorkflowDetail) => void;
}) {
  const [formError, setFormError] = useState<string | null>(null);
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    values: { name: workflow.name, description: workflow.description ?? "" },
  });

  return (
    <form
      className="flex max-w-xl flex-col gap-3"
      onSubmit={form.handleSubmit(async (values) => {
        setFormError(null);

        try {
          const saved = await api<WorkflowDetail>(`/api/v1/organizations/${organizationId}/workflows/${workflow.uuid}`, {
            method: "PATCH",
            body: JSON.stringify({
              name: values.name,
              description: values.description.trim() === "" ? null : values.description.trim(),
            }),
          });
          onSaved(saved);
        } catch (error) {
          setFormError(applyServerErrors(error, form.setError));
        }
      })}
    >
      <TextField control={form.control} name="name" label="Name" />
      <Field>
        <FieldLabel htmlFor="workflow-detail-description">Description</FieldLabel>
        <Textarea id="workflow-detail-description" {...form.register("description")} />
      </Field>
      {formError ? <p className="text-sm text-destructive">{formError}</p> : null}
      <Button type="submit" variant="outline" className="self-start" disabled={form.formState.isSubmitting}>
        Save details
      </Button>
    </form>
  );
}
