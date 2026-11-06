"use client";

import { Handle, Position, type Node, type NodeProps } from "@xyflow/react";
import { AlertTriangle, Check, CircleDashed } from "lucide-react";
import { cn } from "@/lib/utils";
import type { NodeType, WorkflowNodeData } from "@/lib/workflows/types";
import { NODE_PRESENTATION, nodeSummary } from "@/lib/workflows/node-registry";

export type FlowNodeData = WorkflowNodeData & { invalid?: boolean; warning?: string; summary?: string; disconnected?: boolean };
export type FlowNode = Node<FlowNodeData, NodeType>;

export function WorkflowNode({ data, type, selected }: NodeProps<FlowNode>) {
  const kind = (type ?? "start") as NodeType;
  const presentation = NODE_PRESENTATION[kind];
  const Icon = presentation.icon;
  const outcomes = Object.entries(presentation.outcomes);
  const warning = data.warning || (data.disconnected ? "Connect this step to the workflow" : "");
  const StatusIcon = warning || data.invalid ? AlertTriangle : !data.label.trim() ? CircleDashed : Check;

  return (
    <div
      data-node-type={kind}
      role="group"
      className={cn(
        "workflow-node relative w-64 overflow-hidden rounded-xl border border-border bg-card text-card-foreground shadow-(--shadow-card) transition-shadow",
        selected && "border-primary ring-2 ring-primary/30",
        (data.invalid || warning) && "border-status-warning",
      )}
      aria-label={`${presentation.title}: ${data.label || "Untitled"}${warning ? `. ${warning}` : ""}`}
    >
      {kind !== "start" && kind !== "telegram_trigger" && kind !== "email_trigger" && kind !== "slack_trigger" ? <Handle type="target" position={Position.Top} id="in" className="!size-3 !border-2 !border-card !bg-[var(--node-accent)]" aria-label={presentation.inputLabel} /> : null}
      <span className="absolute inset-y-0 left-0 w-1 bg-[var(--node-accent)]" aria-hidden="true" />
      <div className="flex items-start gap-3 p-4 pl-5">
        <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[color-mix(in_oklch,var(--node-accent)_14%,transparent)] text-[var(--node-accent)]">
          <Icon className="size-4" aria-hidden="true" />
        </span>
        <div className="min-w-0 flex-1">
          <p className="text-xs font-medium tracking-wide text-[var(--node-accent)] uppercase">{presentation.title}</p>
          <p className="mt-0.5 break-words text-sm font-semibold leading-5">{data.label || "Untitled step"}</p>
        </div>
        <StatusIcon className={cn("size-3.5 shrink-0", warning || data.invalid ? "text-status-warning" : "text-muted-foreground")} aria-label={warning || (data.invalid ? "Needs attention" : "Configured")} />
      </div>
      <div className="px-4 pb-4">
        <p className="line-clamp-2 text-xs leading-5 text-muted-foreground">{data.description || presentation.description}</p>
        <p className="mt-2 break-words rounded-md bg-muted/60 px-2 py-1.5 text-xs leading-5">{data.summary ?? nodeSummary(kind, data)}</p>
        {warning ? <p className="mt-2 text-xs leading-5 text-status-warning">{warning}</p> : null}
      </div>
      {outcomes.length ? (
        <div className="flex min-h-8 border-t bg-muted/30 rounded-b-xl">
          {outcomes.map(([handle, label]) => (
            <div key={handle} className="relative flex-1 px-1 py-2 text-center text-xs font-medium text-muted-foreground">
              {label}
              <Handle type="source" position={Position.Bottom} id={handle}
                style={{ left: "50%" }}
                className="!size-3 !border-2 !border-card !bg-[var(--node-accent)]"
                aria-label={label} />
            </div>
          ))}
        </div>
      ) : null}
    </div>
  );
}

export const workflowNodeTypes = {
  ai_email_analysis:WorkflowNode, ai_document_analysis:WorkflowNode, ai_slack_analysis:WorkflowNode, ai_summarization:WorkflowNode, ai_classification:WorkflowNode, ai_field_extraction:WorkflowNode, ai_reply_draft:WorkflowNode,
  email_trigger: WorkflowNode, email_filter: WorkflowNode, email_send: WorkflowNode, email_reply: WorkflowNode, attachment_inspector: WorkflowNode, document_validation: WorkflowNode, start: WorkflowNode, approval: WorkflowNode, condition: WorkflowNode, revision: WorkflowNode,
  sms_notification: WorkflowNode, sms_send_message: WorkflowNode,
  slack_trigger: WorkflowNode, slack_filter: WorkflowNode, slack_send_message: WorkflowNode, slack_notification: WorkflowNode,
  end: WorkflowNode, notification: WorkflowNode, assignment: WorkflowNode, delay: WorkflowNode, integration_action: WorkflowNode, telegram_trigger: WorkflowNode, telegram_filter: WorkflowNode, telegram_send_message: WorkflowNode, telegram_notification: WorkflowNode,
};
