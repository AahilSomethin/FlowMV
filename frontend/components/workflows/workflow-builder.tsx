"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { ArrowLeft } from "lucide-react";
import { FormBuilder } from "@/components/workflows/form-builder";
import { NodeInspector } from "@/components/workflows/node-inspector";
import { NodePalette } from "@/components/workflows/node-palette";
import { UnsavedChangesGuard } from "@/components/workflows/unsaved-changes-guard";
import { ValidationList } from "@/components/workflows/validation-list";
import { WorkflowCanvas } from "@/components/workflows/workflow-canvas";
import { ErrorState, describeApiError } from "@/components/patterns/error-state";
import { LoadingState } from "@/components/patterns/loading-state";
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
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Sheet, SheetContent, SheetHeader, SheetTitle } from "@/components/ui/sheet";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { useIsMobile } from "@/hooks/use-mobile";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { validateDefinition } from "@/lib/workflows/definition";
import {
  addWorkflowNode,
  connectNodes,
  createEditorState,
  isDirty,
  markSaved,
  moveNodes,
  redo,
  remember,
  removeWorkflowEdge,
  removeWorkflowNode,
  replaceFields,
  setRevisionTarget,
  undo,
  updateNodeData,
  type EditorState,
} from "@/lib/workflows/editor-state";
import type { NodeType, WorkflowDetail, WorkflowViolation } from "@/lib/workflows/types";

export function WorkflowBuilder({ workflowId }: { workflowId: string }) {
  const { user } = useAuth();
  const organizationId = user?.current_organization?.uuid;
  const isMobile = useIsMobile();
  const [detail, setDetail] = useState<WorkflowDetail | null>(null);
  const [state, setState] = useState<EditorState | null>(null);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [violations, setViolations] = useState<WorkflowViolation[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [conflict, setConflict] = useState(false);
  const [loadedKey, setLoadedKey] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [publishOpen, setPublishOpen] = useState(false);
  const [paletteOpen, setPaletteOpen] = useState(false);
  const [inspectorOpen, setInspectorOpen] = useState(false);
  const dragRef = useRef(false);
  const requestKey = organizationId ? `${organizationId}:${workflowId}` : null;

  useEffect(() => {
    if (!organizationId) {
      return;
    }

    let active = true;

    async function load() {
      try {
        const next = await api<WorkflowDetail>(`/api/v1/organizations/${organizationId}/workflows/${workflowId}`);

        if (!active) {
          return;
        }

        const graph = next.draft?.graph_definition ?? next.published?.graph_definition ?? { nodes: [], edges: [] };
        const form = next.draft?.form_definition ?? next.published?.form_definition ?? { fields: [] };
        setDetail(next);
        setState(createEditorState(graph, form, next.draft?.revision ?? 0));
        setError(null);
        setConflict(false);
        setLoadedKey(`${organizationId}:${workflowId}`);
      } catch (caught) {
        if (!active) {
          return;
        }

        setDetail(null);
        setState(null);
        setError(describeApiError(caught, "Could not open this workflow.").description);
        setLoadedKey(`${organizationId}:${workflowId}`);
      }
    }

    void load();

    return () => {
      active = false;
    };
  }, [organizationId, workflowId]);

  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      const target = event.target;

      if (target instanceof HTMLElement && (target.isContentEditable || ["INPUT", "TEXTAREA", "SELECT"].includes(target.tagName))) {
        return;
      }

      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "z") {
        event.preventDefault();
        setState((current) => (current ? (event.shiftKey ? redo(current) : undo(current)) : current));
      }
    }

    window.addEventListener("keydown", onKey);

    return () => window.removeEventListener("keydown", onKey);
  }, []);

  if (!requestKey || loadedKey !== requestKey) {
    return <LoadingState label="Loading workflow" />;
  }

  if (error || !detail || !state) {
    return <ErrorState title="Workflow unavailable" description={error ?? "This workflow could not be loaded."} />;
  }

  const dirty = isDirty(state);
  const editable = detail.can_update && !isMobile;
  const selected = state.nodes.find((node) => node.id === selectedId) ?? null;
  const payload = {
    revision: state.revision,
    graph_definition: { nodes: state.nodes, edges: state.edges },
    form_definition: { fields: state.fields },
  };

  async function saveDraft() {
    if (!organizationId || !state) {
      return;
    }

    setSaving(true);
    setNotice(null);

    try {
      const saved = await api<WorkflowDetail>(`/api/v1/organizations/${organizationId}/workflows/${workflowId}/draft`, {
        method: "PUT",
        body: JSON.stringify(payload),
      });
      const graph = saved.draft?.graph_definition ?? payload.graph_definition;
      const form = saved.draft?.form_definition ?? payload.form_definition;
      setDetail(saved);
      setState((current) => (current ? markSaved(current, graph, form, saved.draft?.revision ?? current.revision) : current));
      setConflict(false);
      setNotice("Draft saved.");
    } catch (caught) {
      if (caught instanceof ApiError && caught.status === 409) {
        setConflict(true);
      } else if (caught instanceof ApiError && caught.violations.length > 0) {
        setViolations(caught.violations);
      } else {
        setNotice(describeApiError(caught, "Could not save the draft.").description);
      }
    } finally {
      setSaving(false);
    }
  }

  async function validateDraft() {
    const local = validateDefinition(payload.graph_definition, payload.form_definition);
    setViolations(local);

    if (!organizationId || local.length > 0) {
      return local.length === 0;
    }

    try {
      await api(`/api/v1/organizations/${organizationId}/workflows/${workflowId}/draft/validate`, {
        method: "POST",
        body: JSON.stringify(payload),
      });
      setViolations([]);
      setNotice("This draft passes validation.");
      return true;
    } catch (caught) {
      if (caught instanceof ApiError && caught.violations.length > 0) {
        setViolations(caught.violations);
      } else {
        setNotice(describeApiError(caught, "Could not validate the draft.").description);
      }

      return false;
    }
  }

  async function publish() {
    if (!organizationId) {
      return;
    }

    setSaving(true);

    try {
      const published = await api<WorkflowDetail>(`/api/v1/organizations/${organizationId}/workflows/${workflowId}/publish`, {
        method: "POST",
        body: JSON.stringify({ revision: payload.revision }),
      });
      const graph = published.draft?.graph_definition ?? payload.graph_definition;
      const form = published.draft?.form_definition ?? payload.form_definition;
      setDetail(published);
      setState(createEditorState(graph, form, published.draft?.revision ?? payload.revision));
      setViolations([]);
      setPublishOpen(false);
      setNotice(`Published version ${published.current_version_number ?? ""}.`);
    } catch (caught) {
      setPublishOpen(false);

      if (caught instanceof ApiError && caught.violations.length > 0) {
        setViolations(caught.violations);
      } else if (caught instanceof ApiError && caught.status === 409) {
        setConflict(true);
      } else {
        setNotice(describeApiError(caught, "Could not publish the workflow.").description);
      }
    } finally {
      setSaving(false);
    }
  }

  const inspector = (
    <Tabs defaultValue="node">
      <TabsList>
        <TabsTrigger value="node">Node</TabsTrigger>
        <TabsTrigger value="form">Form</TabsTrigger>
      </TabsList>
      <TabsContent value="node">
        <NodeInspector
          node={selected}
          nodes={state.nodes}
          fields={state.fields}
          organizationId={organizationId}
          onChange={(patch) => setState((current) => (current && selected ? updateNodeData(current, selected.id, patch) : current))}
          onRevisionTarget={(approvalId) => setState((current) => (current && selected ? setRevisionTarget(current, selected.id, approvalId) : current))}
        />
      </TabsContent>
      <TabsContent value="form">
        <FormBuilder fields={state.fields} onChange={(fields) => setState((current) => (current ? replaceFields(current, fields) : current))} />
      </TabsContent>
    </Tabs>
  );

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <UnsavedChangesGuard dirty={dirty && editable} />
      <div className="flex shrink-0 flex-wrap items-center gap-2 border-b px-3 py-2">
        <Button variant="ghost" size="sm" asChild>
          <Link href={`/workflows/${workflowId}`}>
            <ArrowLeft className="size-4" />
            Back
          </Link>
        </Button>
        <h1 className="min-w-0 flex-1 truncate text-sm font-medium">{detail.name}</h1>
        {dirty && editable ? <Badge variant="outline">Unsaved changes</Badge> : null}
        {detail.status === "published" && detail.has_unpublished_changes ? <Badge variant="secondary">Unpublished changes</Badge> : null}
        {editable ? (
          <>
            <Button type="button" variant="outline" size="sm" onClick={() => setState((current) => (current ? undo(current) : current))} disabled={state.past.length === 0}>
              Undo
            </Button>
            <Button type="button" variant="outline" size="sm" onClick={() => setState((current) => (current ? redo(current) : current))} disabled={state.future.length === 0}>
              Redo
            </Button>
            <Button type="button" variant="outline" size="sm" onClick={() => void saveDraft()} disabled={saving || !dirty}>
              Save
            </Button>
            <Button type="button" variant="outline" size="sm" onClick={() => void validateDraft()}>
              Check
            </Button>
          </>
        ) : null}
        {detail.can_publish ? (
          <Button
            type="button"
            size="sm"
            disabled={saving}
            onClick={() => {
              if (dirty) {
                setNotice("Save the draft before publishing.");
                return;
              }

              void validateDraft().then((valid) => {
                if (valid) {
                  setPublishOpen(true);
                }
              });
            }}
          >
            Publish
          </Button>
        ) : null}
      </div>
      {notice ? (
        <Alert>
          <AlertDescription>{notice}</AlertDescription>
        </Alert>
      ) : null}
      {conflict ? (
        <Alert variant="destructive">
          <AlertDescription>This draft was updated somewhere else. Reload it before saving over that work.</AlertDescription>
        </Alert>
      ) : null}
      {violations.length > 0 ? (
        <div className="px-3 py-2">
          <ValidationList violations={violations} />
        </div>
      ) : null}
      {editable ? (
        <div className="flex min-h-0 flex-1">
          <aside className="hidden w-64 shrink-0 overflow-y-auto border-r p-3 lg:block">
            <NodePalette
              startDisabled={state.nodes.some((node) => node.type === "start")}
              onAdd={(type) =>
                setState((current) =>
                  current
                    ? addWorkflowNode(current, type, {
                        x: 40 + (current.nodes.length % 3) * 320,
                        y: 40 + Math.floor(current.nodes.length / 3) * 200,
                      })
                    : current,
                )
              }
            />
          </aside>
          <div className="relative min-h-0 min-w-0 flex-1">
            <div className="absolute top-2 right-2 z-10 flex gap-2 lg:hidden">
              <Button type="button" size="sm" variant="secondary" onClick={() => setPaletteOpen(true)}>
                Nodes
              </Button>
              <Button type="button" size="sm" variant="secondary" onClick={() => setInspectorOpen(true)}>
                Properties
              </Button>
            </div>
            <WorkflowCanvas
              nodes={state.nodes}
              edges={state.edges}
              violations={violations}
              onSelect={setSelectedId}
              onMove={(positions, dragging) =>
                setState((current) => {
                  if (!current) {
                    return current;
                  }

                  let next = current;

                  if (dragging && !dragRef.current) {
                    next = remember(next);
                    dragRef.current = true;
                  }

                  if (!dragging) {
                    dragRef.current = false;
                  }

                  return moveNodes(next, positions, false);
                })
              }
              onConnect={(connection) => setState((current) => (current ? connectNodes(current, connection) : current))}
              onDeleteNodes={(ids) => setState((current) => (current ? ids.reduce((next, id) => removeWorkflowNode(next, id), current) : current))}
              onDeleteEdges={(ids) => setState((current) => (current ? ids.reduce((next, id) => removeWorkflowEdge(next, id), current) : current))}
              onDropNode={(type: NodeType, position) => {
                setState((current) => (current ? addWorkflowNode(current, type, position) : current));
                setPaletteOpen(false);
              }}
            />
          </div>
          <aside className="hidden w-80 shrink-0 overflow-y-auto border-l p-3 lg:block">{inspector}</aside>
          <Sheet open={paletteOpen} onOpenChange={setPaletteOpen}>
            <SheetContent side="left" className="w-72">
              <SheetHeader>
                <SheetTitle>Nodes</SheetTitle>
              </SheetHeader>
              <NodePalette
                startDisabled={state.nodes.some((node) => node.type === "start")}
                onAdd={(type) => {
                  setState((current) =>
                    current
                      ? addWorkflowNode(current, type, {
                          x: 40 + (current.nodes.length % 3) * 320,
                          y: 40 + Math.floor(current.nodes.length / 3) * 200,
                        })
                      : current,
                  );
                  setPaletteOpen(false);
                }}
              />
            </SheetContent>
          </Sheet>
          <Sheet open={inspectorOpen} onOpenChange={setInspectorOpen}>
            <SheetContent side="right" className="w-80 overflow-y-auto">
              <SheetHeader>
                <SheetTitle>Properties</SheetTitle>
              </SheetHeader>
              {inspector}
            </SheetContent>
          </Sheet>
        </div>
      ) : (
        <div className="flex min-h-0 flex-1 flex-col gap-3 p-4">
          {isMobile && detail.can_update ? (
            <p className="text-sm text-muted-foreground">Editing needs a wider screen. You can review the workflow here and edit it on a tablet or desktop.</p>
          ) : null}
          {!detail.can_update ? <p className="text-sm text-muted-foreground">You can review this workflow. Editing is limited to admins and managers.</p> : null}
          <div className="h-[min(70dvh,36rem)] min-h-64 overflow-hidden rounded-xl ring-1 ring-foreground/10">
            <WorkflowCanvas
              nodes={state.nodes}
              edges={state.edges}
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
        </div>
      )}
      <AlertDialog open={publishOpen} onOpenChange={setPublishOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Publish this workflow?</AlertDialogTitle>
            <AlertDialogDescription>
              Publishing stores an immutable version. Later edits stay in the draft and do not change the published version. The workflow is not executed by this action.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel type="button">Cancel</AlertDialogCancel>
            <Button type="button" onClick={() => void publish()} disabled={saving}>
              Publish version
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
