"use client";

import { useEffect, useState } from "react";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { api } from "@/lib/api";
import { MousePointer2, SlidersHorizontal } from "lucide-react";
import { NODE_PRESENTATION, nodeSummary } from "@/lib/workflows/node-registry";
import type { Member } from "@/lib/organizations";
import type {
  AssigneeRole,
  ConditionOperator,
  FormField,
  NotificationChannel,
  WorkflowNode,
  WorkflowNodeData,
  WorkflowViolation,
} from "@/lib/workflows/types";
import { OPERATORS, VALUELESS_OPERATORS } from "@/lib/workflows/types";

const ROLES: AssigneeRole[] = ["admin", "manager", "employee"];
const CHANNELS: NotificationChannel[] = ["in_app", "email"];

type MemberOption = Member & { user_id?: number };

export function NodeInspector({
  node,
  nodes,
  fields,
  organizationId,
  violations = [],
  onChange,
  onRevisionTarget,
}: {
  node: WorkflowNode | null;
  nodes: WorkflowNode[];
  fields: FormField[];
  organizationId?: string | null;
  violations?: WorkflowViolation[];
  onChange: (patch: Partial<WorkflowNodeData>) => void;
  onRevisionTarget: (approvalId: string) => void;
}) {
  const [memberResult, setMemberResult] = useState<{ organizationId: string; items: MemberOption[] } | null>(null);
  const members = memberResult && memberResult.organizationId === organizationId ? memberResult.items : [];

  useEffect(() => {
    if (!organizationId || node?.type !== "assignment") {
      return;
    }

    let active = true;

    void api<MemberOption[]>(`/api/v1/organizations/${organizationId}/members`)
      .then((result) => {
        if (active) {
          setMemberResult({ organizationId: organizationId!, items: result });
        }
      })
      .catch(() => {
        if (active) {
          setMemberResult({ organizationId: organizationId!, items: [] });
        }
      });

    return () => {
      active = false;
    };
  }, [organizationId, node?.type]);
  if (!node) {
    return <div className="flex flex-col items-center gap-3 py-12 text-center"><MousePointer2 className="size-8 text-primary/60" aria-hidden="true" /><h2 className="text-sm font-semibold">Make each step your own</h2><p className="text-sm leading-6 text-muted-foreground">Select a step to configure it. Start with your request form, then add the decisions and tasks it needs.</p><p className="text-xs leading-5 text-muted-foreground">Save your draft at any time. Check the workflow before publishing it.</p></div>;
  }

  const approvals = nodes.filter((item) => item.type === "approval");

  return (
    <div className="flex flex-col gap-4">
      <div className="border-b pb-4"><h2 className="flex items-center gap-2 text-sm font-semibold"><SlidersHorizontal className="size-4 text-primary" aria-hidden="true" />{NODE_PRESENTATION[node.type].title} settings</h2><p className="mt-2 text-xs leading-5 text-muted-foreground">{NODE_PRESENTATION[node.type].description}</p><p className="mt-2 rounded-lg bg-muted p-2 text-xs leading-5">{nodeSummary(node.type, node.data, fields)}</p></div>
      {violations.length ? <div className="rounded-lg border border-status-warning/30 bg-status-warning/5 p-3" role="status"><p className="text-xs font-semibold text-status-warning">Finish this step</p><ul className="mt-1 space-y-1 text-xs leading-5">{violations.map((item, index) => <li key={`${item.code}-${index}`}>{item.message}</li>)}</ul></div> : null}
      <div className="flex flex-col gap-1.5">
        <Label htmlFor="node-label">Step name</Label>
        <Input id="node-label" placeholder="e.g. Manager review" value={node.data.label} maxLength={120} onChange={(event) => onChange({ label: event.target.value })} />
      </div>
      <div className="flex flex-col gap-1.5">
        <Label htmlFor="node-description">Description</Label>
        <Textarea
          id="node-description"
          value={node.data.description}
          maxLength={2000}
          onChange={(event) => onChange({ description: event.target.value })}
        />
      </div>
      {node.type === "approval" ? (
        <fieldset className="flex flex-col gap-2">
          <legend className="text-sm font-medium">Approver roles</legend>
          {ROLES.map((role) => {
            const checked = node.data.assignee_roles?.includes(role) ?? false;

            return (
              <label key={role} className="flex items-center gap-2 text-sm capitalize">
                <input
                  type="checkbox"
                  className="size-4 shrink-0 rounded border border-input accent-current"
                  checked={checked}
                  onChange={(event) => {
                    const current = new Set(node.data.assignee_roles ?? []);

                    if (event.target.checked) {
                      current.add(role);
                    } else {
                      current.delete(role);
                    }

                    onChange({ assignee_roles: ROLES.filter((item) => current.has(item)) });
                  }}
                />
                {role}
              </label>
            );
          })}
        </fieldset>
      ) : null}
      {node.type === "condition" ? <ConditionFields node={node} fields={fields} onChange={onChange} /> : null}
      {node.type === "revision" ? (
        <div className="flex flex-col gap-3">
          <p className="text-sm leading-6 text-muted-foreground">
            Rejection enters this step. The original submitter corrects the request, then it returns to the selected approval.
            After the revision limit is reached, connect the Limit reached outcome to an End step with a Rejected result.
          </p>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="max-returns">Maximum attempts</Label>
            <Input
              id="max-returns"
              type="number"
              min={1}
              max={10}
              value={node.data.max_returns ?? ""}
              onChange={(event) => onChange({ max_returns: event.target.value === "" ? null : Number(event.target.value) })}
            />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="revision-approval">Return to approval</Label>
            <Select value={node.data.return_to_node_id ?? ""} onValueChange={onRevisionTarget}>
              <SelectTrigger id="revision-approval" className="w-full">
                <SelectValue placeholder="Choose an approval" />
              </SelectTrigger>
              <SelectContent>
                {approvals.map((approval) => (
                  <SelectItem key={approval.id} value={approval.id}>
                    {approval.data.label || "Approval"}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </div>
      ) : null}
      {node.type === "end" ? (
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="end-outcome">Request outcome</Label>
          <Select value={node.data.result ?? ""} onValueChange={(value) => onChange({ result: value as "completed" | "rejected" })}>
            <SelectTrigger id="end-outcome" className="w-full">
              <SelectValue placeholder="Choose a result" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="completed">Completed</SelectItem>
              <SelectItem value="rejected">Rejected</SelectItem>
            </SelectContent>
          </Select>
        </div>
      ) : null}
      {node.type === "notification" ? (
        <NotificationFields node={node} fields={fields} onChange={onChange} />
      ) : null}
      {node.type === "assignment" ? (
        <AssignmentFields node={node} members={members} onChange={onChange} />
      ) : null}
      {node.type === "delay" ? <DelayFields key={node.id} node={node} onChange={onChange} /> : null}
    </div>
  );
}

function NotificationFields({
  node,
  fields,
  onChange,
}: {
  node: WorkflowNode;
  fields: FormField[];
  onChange: (patch: Partial<WorkflowNodeData>) => void;
}) {
  const templateFields = fields.filter((field) => field.type !== "file");

  return (
    <div className="flex flex-col gap-3">
      <fieldset className="flex flex-col gap-2">
        <legend className="text-sm font-medium">Channels</legend>
        {CHANNELS.map((channel) => {
          const checked = node.data.channels?.includes(channel) ?? false;

          return (
            <label key={channel} className="flex items-center gap-2 text-sm capitalize">
              <input
                type="checkbox"
                className="size-4 shrink-0 rounded border border-input accent-current"
                checked={checked}
                onChange={(event) => {
                  const current = new Set(node.data.channels ?? []);

                  if (event.target.checked) {
                    current.add(channel);
                  } else {
                    current.delete(channel);
                  }

                  onChange({ channels: CHANNELS.filter((item) => current.has(item)) });
                }}
              />
              {channel === "in_app" ? "In-app" : "Email"}
            </label>
          );
        })}
      </fieldset>
      <fieldset className="flex flex-col gap-2">
        <legend className="text-sm font-medium">Recipient roles</legend>
        {ROLES.map((role) => {
          const checked = node.data.recipient_roles?.includes(role) ?? false;

          return (
            <label key={role} className="flex items-center gap-2 text-sm capitalize">
              <input
                type="checkbox"
                className="size-4 shrink-0 rounded border border-input accent-current"
                checked={checked}
                onChange={(event) => {
                  const current = new Set(node.data.recipient_roles ?? []);

                  if (event.target.checked) {
                    current.add(role);
                  } else {
                    current.delete(role);
                  }

                  onChange({ recipient_roles: ROLES.filter((item) => current.has(item)) });
                }}
              />
              {role}
            </label>
          );
        })}
      </fieldset>
      <label className="flex items-center gap-2 text-sm">
        <input
          type="checkbox"
          className="size-4 shrink-0 rounded border border-input accent-current"
          checked={node.data.include_submitter ?? false}
          onChange={(event) => onChange({ include_submitter: event.target.checked })}
        />
        Include submitter
      </label>
      <label className="flex items-center gap-2 text-sm">
        <input
          type="checkbox"
          className="size-4 shrink-0 rounded border border-input accent-current"
          checked={node.data.include_task_participants ?? false}
          onChange={(event) => onChange({ include_task_participants: event.target.checked })}
        />
        Include task participants
      </label>
      {templateFields.length > 0 ? (
        <fieldset className="flex flex-col gap-2">
          <legend className="text-sm font-medium">Fields in message</legend>
          {templateFields.map((field) => {
            const checked = node.data.allowed_field_ids?.includes(field.id) ?? false;

            return (
              <label key={field.id} className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  className="size-4 shrink-0 rounded border border-input accent-current"
                  checked={checked}
                  onChange={(event) => {
                    const current = new Set(node.data.allowed_field_ids ?? []);

                    if (event.target.checked) {
                      current.add(field.id);
                    } else {
                      current.delete(field.id);
                    }

                    onChange({ allowed_field_ids: [...current] });
                  }}
                />
                {field.label || field.id}
              </label>
            );
          })}
        </fieldset>
      ) : null}
      <div className="flex flex-col gap-1.5">
        <Label htmlFor="notification-subject">Subject</Label>
        <Input
          id="notification-subject"
          value={node.data.subject ?? ""}
          maxLength={160}
          onChange={(event) => onChange({ subject: event.target.value })}
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <Label htmlFor="notification-body">Body</Label>
        <Textarea
          id="notification-body"
          value={node.data.body ?? ""}
          maxLength={4000}
          onChange={(event) => onChange({ body: event.target.value })}
        />
      </div>
    </div>
  );
}

function AssignmentFields({
  node,
  members,
  onChange,
}: {
  node: WorkflowNode;
  members: MemberOption[];
  onChange: (patch: Partial<WorkflowNodeData>) => void;
}) {
  const mode = node.data.assignee_mode ?? "role";
  const memberOptions = members.filter((member): member is MemberOption & { user_id: number } => typeof member.user_id === "number");

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-col gap-1.5">
        <Label htmlFor="assignment-mode">Assignee</Label>
        <Select value={mode} onValueChange={(value) => onChange({ assignee_mode: value as "role" | "member" })}>
          <SelectTrigger id="assignment-mode" className="w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="role">Role</SelectItem>
            <SelectItem value="member">Member</SelectItem>
          </SelectContent>
        </Select>
      </div>
      {mode === "role" ? (
        <fieldset className="flex flex-col gap-2">
          <legend className="text-sm font-medium">Assignable roles</legend>
          {ROLES.map((role) => {
            const checked = node.data.assignee_roles?.includes(role) ?? false;

            return (
              <label key={role} className="flex items-center gap-2 text-sm capitalize">
                <input
                  type="checkbox"
                  className="size-4 shrink-0 rounded border border-input accent-current"
                  checked={checked}
                  onChange={(event) => {
                    const current = new Set(node.data.assignee_roles ?? []);

                    if (event.target.checked) {
                      current.add(role);
                    } else {
                      current.delete(role);
                    }

                    onChange({ assignee_roles: ROLES.filter((item) => current.has(item)) });
                  }}
                />
                {role}
              </label>
            );
          })}
        </fieldset>
      ) : memberOptions.length > 0 ? (
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="assignment-member">Organization member</Label>
          <Select
            value={node.data.assignee_user_id != null ? String(node.data.assignee_user_id) : ""}
            onValueChange={(value) => onChange({ assignee_user_id: value === "" ? null : Number(value) })}
          >
            <SelectTrigger id="assignment-member" className="w-full">
              <SelectValue placeholder="Choose a member" />
            </SelectTrigger>
            <SelectContent>
              {memberOptions.map((member) => (
                <SelectItem key={member.uuid} value={String(member.user_id)}>
                  {member.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      ) : (
        <div className="flex flex-col gap-1.5">
          <p className="text-sm text-muted-foreground" role="status">No members are available to select. Choose a role instead, or reopen this step to try loading members again.</p>
        </div>
      )}
      <label className="flex items-center gap-2 text-sm">
        <input
          type="checkbox"
          className="size-4 shrink-0 rounded border border-input accent-current"
          checked={node.data.exclude_submitter ?? true}
          onChange={(event) => onChange({ exclude_submitter: event.target.checked })}
        />
        Exclude submitter when possible
      </label>
      <div className="flex flex-col gap-1.5">
        <Label htmlFor="assignment-instructions">Instructions</Label>
        <Textarea
          id="assignment-instructions"
          value={node.data.instructions ?? ""}
          maxLength={2000}
          onChange={(event) => onChange({ instructions: event.target.value })}
        />
      </div>
    </div>
  );
}

function DelayFields({
  node,
  onChange,
}: {
  node: WorkflowNode;
  onChange: (patch: Partial<WorkflowNodeData>) => void;
}) {
  const mode = node.data.resume_mode ?? "duration";
  const [unit, setUnit] = useState(() => {
    const seconds = node.data.duration_seconds ?? 0;
    return seconds && seconds % 86400 === 0 ? 86400 : seconds && seconds % 3600 === 0 ? 3600 : seconds && seconds % 60 === 0 ? 60 : 1;
  });

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-col gap-1.5">
        <Label htmlFor="delay-mode">Resume</Label>
        <Select value={mode} onValueChange={(value) => onChange({ resume_mode: value as "duration" | "until" })}>
          <SelectTrigger id="delay-mode" className="w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="duration">After a duration</SelectItem>
            <SelectItem value="until">At a date and time</SelectItem>
          </SelectContent>
        </Select>
      </div>
      {mode === "duration" ? (
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="delay-seconds">Waiting period</Label>
          <div className="flex gap-2"><Input
            id="delay-seconds"
            type="number"
            min={1 / unit}
            max={2_592_000 / unit}
            step="any"
            value={node.data.duration_seconds == null ? "" : node.data.duration_seconds / unit}
            onChange={(event) => onChange({ duration_seconds: event.target.value === "" ? null : Math.round(Number(event.target.value) * unit) })}
          /><Select value={String(unit)} onValueChange={(value) => setUnit(Number(value))}><SelectTrigger aria-label="Waiting period unit" className="w-32"><SelectValue /></SelectTrigger><SelectContent>{[[1, "Seconds"], [60, "Minutes"], [3600, "Hours"], [86400, "Days"]].map(([value, label]) => <SelectItem key={value} value={String(value)}>{label}</SelectItem>)}</SelectContent></Select></div>
          <p className="text-xs leading-5 text-muted-foreground">Between 1 second and 30 days.</p>
        </div>
      ) : (
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="delay-until">Resume at</Label>
          <Input
            id="delay-until"
            type="datetime-local"
            value={isoToLocalInput(node.data.resume_at)}
            onChange={(event) => onChange({ resume_at: localInputToIso(event.target.value) })}
          />
        </div>
      )}
    </div>
  );
}

function isoToLocalInput(value: string | null | undefined): string {
  if (!value) {
    return "";
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return "";
  }

  const pad = (part: number) => String(part).padStart(2, "0");

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function localInputToIso(value: string): string | null {
  if (!value) {
    return null;
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return null;
  }

  return date.toISOString();
}

function ConditionFields({
  node,
  fields,
  onChange,
}: {
  node: WorkflowNode;
  fields: FormField[];
  onChange: (patch: Partial<WorkflowNodeData>) => void;
}) {
  const rules = node.data.rules ?? [];

  function updateRule(id: string, patch: Partial<(typeof rules)[number]>) {
    onChange({
      rules: rules.map((rule) => (rule.id === id ? { ...rule, ...patch } : rule)),
    });
  }

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-col gap-1.5">
        <Label htmlFor="condition-match">Match</Label>
        <Select value={node.data.match ?? "all"} onValueChange={(value) => onChange({ match: value as "all" | "any" })}>
          <SelectTrigger id="condition-match" className="w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All rules</SelectItem>
            <SelectItem value="any">Any rule</SelectItem>
          </SelectContent>
        </Select>
      </div>
      {fields.length === 0 ? <p className="text-sm text-muted-foreground">Add a form field before writing a condition.</p> : null}
      {rules.map((rule) => {
        const field = fields.find((item) => item.id === rule.field_id);
        const operators: ConditionOperator[] = field ? OPERATORS[field.type] : [];

        return (
          <div key={rule.id} className="flex flex-col gap-2 rounded-lg bg-muted/60 p-2">
            <Select value={rule.field_id} onValueChange={(value) => updateRule(rule.id, { field_id: value, operator: "equals", value: null })}>
              <SelectTrigger aria-label="Answer to check" className="w-full">
                <SelectValue placeholder="Field" />
              </SelectTrigger>
              <SelectContent>
                {fields.map((item) => (
                  <SelectItem key={item.id} value={item.id}>
                    {item.label || item.id}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Select value={rule.operator} onValueChange={(value) => updateRule(rule.id, { operator: value as ConditionOperator, value: null })}>
              <SelectTrigger aria-label="Comparison" className="w-full">
                <SelectValue placeholder="Operator" />
              </SelectTrigger>
              <SelectContent>
                {operators.map((operator) => (
                  <SelectItem key={operator} value={operator}>
                    {operator.replaceAll("_", " ")}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {VALUELESS_OPERATORS.has(rule.operator) ? null : (
              <Input
                aria-label="Comparison value"
                value={rule.value ?? ""}
                onChange={(event) => {
                  const raw = event.target.value;
                  updateRule(rule.id, { value: field?.type === "number" && raw !== "" ? Number(raw) : raw });
                }}
              />
            )}
            <button type="button" className="self-start text-sm text-destructive" onClick={() => onChange({ rules: rules.filter((item) => item.id !== rule.id) })}>
              Remove rule
            </button>
          </div>
        );
      })}
      <button
        type="button"
        className="self-start text-sm font-medium text-primary disabled:opacity-50"
        disabled={fields.length === 0}
        onClick={() =>
          onChange({
            rules: [
              ...rules,
              {
                id: crypto.randomUUID(),
                field_id: fields[0]?.id ?? "",
                operator: fields[0] ? OPERATORS[fields[0].type][0] : "equals",
                value: null,
              },
            ],
          })
        }
      >
        Add rule
      </button>
    </div>
  );
}
