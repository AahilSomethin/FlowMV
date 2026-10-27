"use client";

import { Handle, Position, type Node, type NodeProps } from "@xyflow/react";
import { cn } from "cn";
import type { NodeType, WorkflowNodeData } from "@/lib/workflows/types";
import { NODE_COPY } from "@/lib/workflows/types";

export type FlowNodeData = WorkflowNodeData & { invalid?: boolean };
export type FlowNode = Node<FlowNodeData, NodeType>;

export function WorkflowNode({ data, type, selected }: NodeProps<FlowNode>) {
  const kind = (type ?? "start") as NodeType;

  return (
    <div
      data-node-type={kind}
      role="group"
      className={cn(
        "workflow-node relative w-56 rounded-xl bg-card px-3 py-2 text-card-foreground shadow-sm ring-1 ring-[var(--node-accent)]",
        selected && "ring-2",
        data.invalid && "ring-destructive",
      )}
      aria-label={`${NODE_COPY[kind].title}: ${data.label || "Untitled"}`}
    >
      {kind !== "start" ? (
        <Handle type="target" position={Position.Top} id="in" className="size-2.5 border-2 border-background bg-[var(--node-accent)]" aria-label="Input" />
      ) : null}
      <p className="text-xs font-medium text-[var(--node-accent)]">{NODE_COPY[kind].title}</p>
      <p className="truncate text-sm font-medium">{data.label || "Untitled"}</p>
      {kind === "approval" ? (
        <p className="truncate text-xs text-muted-foreground">{data.assignee_roles?.join(", ") || "No role selected"}</p>
      ) : null}
      {kind === "end" ? (
        <p className="text-xs text-muted-foreground">{data.result === "rejected" ? "Rejected" : "Completed"}</p>
      ) : null}
      {kind === "revision" ? (
        <p className="text-xs text-muted-foreground">{data.max_returns ?? 0} attempts, then rejected</p>
      ) : null}
      {kind === "notification" ? (
        <p className="truncate text-xs text-muted-foreground">{formatChannels(data.channels)}</p>
      ) : null}
      {kind === "assignment" ? (
        <p className="truncate text-xs text-muted-foreground">{formatAssignment(data)}</p>
      ) : null}
      {kind === "delay" ? <p className="truncate text-xs text-muted-foreground">{formatDelay(data)}</p> : null}
      <Outcome
        handle="out"
        label="Next"
        hidden={!["start", "notification", "assignment", "delay"].includes(kind)}
        position={Position.Bottom}
      />
      <Outcome handle="approved" label="Approved" hidden={kind !== "approval"} position={Position.Right} />
      <Outcome handle="rejected" label="Rejected" hidden={kind !== "approval"} position={Position.Left} />
      <Outcome handle="true" label="True" hidden={kind !== "condition"} position={Position.Right} />
      <Outcome handle="false" label="False" hidden={kind !== "condition"} position={Position.Left} />
      <Outcome handle="return" label="Return" hidden={kind !== "revision"} position={Position.Left} />
      <Outcome handle="exhausted" label="Exhausted" hidden={kind !== "revision"} position={Position.Right} />
    </div>
  );
}

function Outcome({
  handle,
  label,
  hidden,
  position,
}: {
  handle: string;
  label: string;
  hidden: boolean;
  position: Position;
}) {
  if (hidden) {
    return null;
  }

  const side = position === Position.Left ? "left-2" : position === Position.Right ? "right-2" : "left-1/2 -translate-x-1/2";

  return (
    <>
      <span className={cn("pointer-events-none absolute bottom-1 text-[10px] font-medium text-muted-foreground", side)}>{label}</span>
      <Handle
        type="source"
        position={position}
        id={handle}
        className="size-2.5 border-2 border-background bg-[var(--node-accent)]"
        aria-label={label}
      />
    </>
  );
}

export const workflowNodeTypes = {
  start: WorkflowNode,
  approval: WorkflowNode,
  condition: WorkflowNode,
  revision: WorkflowNode,
  end: WorkflowNode,
  notification: WorkflowNode,
  assignment: WorkflowNode,
  delay: WorkflowNode,
};

function formatChannels(channels: WorkflowNodeData["channels"]): string {
  if (!channels?.length) {
    return "No channel selected";
  }

  return channels.map((channel) => (channel === "in_app" ? "In-app" : "Email")).join(", ");
}

function formatAssignment(data: WorkflowNodeData): string {
  const mode = data.assignee_mode ?? "role";

  if (mode === "member") {
    return data.assignee_user_id != null ? `Member #${data.assignee_user_id}` : "No member selected";
  }

  return data.assignee_roles?.join(", ") || "No role selected";
}

function formatDelay(data: WorkflowNodeData): string {
  const mode = data.resume_mode ?? "duration";

  if (mode === "until") {
    return data.resume_at ? `Until ${data.resume_at}` : "No resume time";
  }

  const seconds = data.duration_seconds;

  if (seconds == null) {
    return "No duration";
  }

  if (seconds % 86400 === 0) {
    const days = seconds / 86400;

    return `${days} day${days === 1 ? "" : "s"}`;
  }

  if (seconds % 3600 === 0) {
    const hours = seconds / 3600;

    return `${hours} hour${hours === 1 ? "" : "s"}`;
  }

  if (seconds % 60 === 0) {
    const minutes = seconds / 60;

    return `${minutes} min`;
  }

  return `${seconds}s`;
}
