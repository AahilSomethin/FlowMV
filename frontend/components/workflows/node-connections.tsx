"use client";

import { ArrowRight } from "lucide-react";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { NODE_PRESENTATION } from "@/lib/workflows/node-registry";
import type { WorkflowEdge, WorkflowNode } from "@/lib/workflows/types";

export function NodeConnections({ node, nodes, edges, onConnect }: {
  node: WorkflowNode;
  nodes: WorkflowNode[];
  edges: WorkflowEdge[];
  onConnect: (handle: string, target: string) => void;
}) {
  const outcomes = Object.entries(NODE_PRESENTATION[node.type].outcomes);
  if (!outcomes.length) return null;
  return <section className="mt-5 border-t pt-4">
    <h3 className="flex items-center gap-2 text-sm font-semibold"><ArrowRight className="size-4 text-primary" aria-hidden="true" />What happens next</h3>
    <p className="my-2 text-xs leading-5 text-muted-foreground">Choose the next step for each outcome, or draw a connection on the canvas.</p>
    <div className="space-y-3">{outcomes.map(([handle, label]) => {
      const edge = edges.find((item) => item.source === node.id && item.source_handle === handle);
      return <div key={handle} className="space-y-1.5"><p className="text-xs font-medium">{label}</p><Select value={edge?.target ?? "__none__"} onValueChange={(value) => onConnect(handle, value === "__none__" ? "" : value)}><SelectTrigger className="w-full" aria-label={`${label}: next step`}><SelectValue placeholder="Choose a step" /></SelectTrigger><SelectContent><SelectItem value="__none__">Not connected</SelectItem>{nodes.filter((target) => target.id !== node.id && target.type !== "start" && (handle !== "return" || target.type === "approval")).map((target) => <SelectItem key={target.id} value={target.id}>{target.data.label || NODE_PRESENTATION[target.type].title}</SelectItem>)}</SelectContent></Select></div>;
    })}</div>
  </section>;
}
