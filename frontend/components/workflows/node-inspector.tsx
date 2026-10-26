"use client";

import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import type { AssigneeRole, ConditionOperator, FormField, WorkflowNode, WorkflowNodeData } from "@/lib/workflows/types";
import { OPERATORS, VALUELESS_OPERATORS } from "@/lib/workflows/types";

const ROLES: AssigneeRole[] = ["admin", "manager", "employee"];

export function NodeInspector({
  node,
  nodes,
  fields,
  onChange,
  onRevisionTarget,
}: {
  node: WorkflowNode | null;
  nodes: WorkflowNode[];
  fields: FormField[];
  onChange: (patch: Partial<WorkflowNodeData>) => void;
  onRevisionTarget: (approvalId: string) => void;
}) {
  if (!node) {
    return <p className="text-sm text-muted-foreground">Select a node to edit its label, description, and outcome settings.</p>;
  }

  const approvals = nodes.filter((item) => item.type === "approval");

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-1.5">
        <Label htmlFor="node-label">Label</Label>
        <Input id="node-label" value={node.data.label} maxLength={120} onChange={(event) => onChange({ label: event.target.value })} />
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
            Attempts are counted per submission. After the limit, the exhausted path must end at a rejected End. This is stored for later execution and is not run here.
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
            <Label>Return to approval</Label>
            <Select value={node.data.return_to_node_id ?? ""} onValueChange={onRevisionTarget}>
              <SelectTrigger className="w-full">
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
          <Label>Terminal result</Label>
          <Select value={node.data.result ?? ""} onValueChange={(value) => onChange({ result: value as "completed" | "rejected" })}>
            <SelectTrigger className="w-full">
              <SelectValue placeholder="Choose a result" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="completed">Completed</SelectItem>
              <SelectItem value="rejected">Rejected</SelectItem>
            </SelectContent>
          </Select>
        </div>
      ) : null}
    </div>
  );
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
        <Label>Match</Label>
        <Select value={node.data.match ?? "all"} onValueChange={(value) => onChange({ match: value as "all" | "any" })}>
          <SelectTrigger className="w-full">
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
              <SelectTrigger className="w-full">
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
              <SelectTrigger className="w-full">
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
