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
      <Outcome handle="out" label="Next" hidden={kind !== "start"} position={Position.Bottom} />
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
};
