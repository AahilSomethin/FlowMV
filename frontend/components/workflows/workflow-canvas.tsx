"use client";

import { useEffect, useRef, useState } from "react";
import {
  Background,
  Controls,
  MiniMap,
  Panel,
  ReactFlow,
  ReactFlowProvider,
  useReactFlow,
  type Connection,
  type NodeChange,
} from "@xyflow/react";
import "@xyflow/react/dist/style.css";
import type { FormField, NodeType, WorkflowEdge, WorkflowNode, WorkflowViolation } from "@/lib/workflows/types";
import { SOURCE_HANDLES } from "@/lib/workflows/types";
import { NODE_PRESENTATION, nodeSummary } from "@/lib/workflows/node-registry";
import { Button } from "@/components/ui/button";
import { workflowNodeTypes, type FlowNode } from "@/components/workflows/workflow-node";

type CanvasProps = {
  nodes: WorkflowNode[];
  edges: WorkflowEdge[];
  violations: WorkflowViolation[];
  fields?: FormField[];
  selectedNodeId?: string | null;
  readOnly?: boolean;
  onSelect: (id: string | null) => void;
  onMove: (positions: Record<string, { x: number; y: number }>, dragging: boolean) => void;
  onConnect: (connection: { source: string; target: string; sourceHandle: string }) => void;
  onDeleteNodes: (ids: string[]) => void;
  onDeleteEdges: (ids: string[]) => void;
  onDropNode: (type: NodeType, position: { x: number; y: number }) => void;
};

export function WorkflowCanvas(props: CanvasProps) {
  return (
    <ReactFlowProvider>
      <CanvasInner {...props} />
    </ReactFlowProvider>
  );
}

function CanvasInner({
  nodes,
  edges,
  violations,
  fields = [],
  selectedNodeId,
  readOnly = false,
  onSelect,
  onMove,
  onConnect,
  onDeleteNodes,
  onDeleteEdges,
  onDropNode,
}: CanvasProps) {
  const { screenToFlowPosition, fitView } = useReactFlow();
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [showOverview, setShowOverview] = useState(false);
  const [measurements, setMeasurements] = useState<Record<string, { width: number; height: number }>>({});
  const [menu, setMenu] = useState<{ id: string; x: number; y: number } | null>(null);
  const fitted = useRef(false);
  const invalid = new Set(violations.map((item) => item.node_id).filter((id): id is string => Boolean(id)));
  const flowNodes: FlowNode[] = nodes.map((node) => ({
    id: node.id,
    type: node.type,
    position: node.position,
    measured: measurements[node.id],
    selected: (selectedNodeId === undefined ? selectedId : selectedNodeId) === node.id,
    data: {
      ...node.data,
      invalid: invalid.has(node.id),
      warning: violations.find((item) => item.node_id === node.id)?.message,
      disconnected: node.type !== "start" && !edges.some((edge) => edge.target === node.id),
      summary: nodeSummary(node.type, node.data, fields),
    },
    draggable: !readOnly,
    connectable: !readOnly,
  }));
  const flowEdges = edges.map((edge) => ({
    id: edge.id,
    source: edge.source,
    target: edge.target,
    sourceHandle: edge.source_handle,
    targetHandle: edge.target_handle,
    label: edge.source_handle === "out" ? undefined : NODE_PRESENTATION[nodes.find((node) => node.id === edge.source)?.type ?? "start"].outcomes[edge.source_handle],
  }));

  useEffect(() => {
    if (fitted.current || nodes.length === 0) return;
    const frame = requestAnimationFrame(() => {
      void fitView({ padding: 0.25, maxZoom: 1, duration: 0 });
      fitted.current = true;
    });

    return () => cancelAnimationFrame(frame);
  }, [fitView, nodes.length]);

  function onNodesChange(changes: NodeChange<FlowNode>[]) {
    const positions: Record<string, { x: number; y: number }> = {};
    let dragging = false;

    for (const change of changes) {
      if (change.type === "dimensions" && change.dimensions) {
        const dimensions = change.dimensions;
        setMeasurements((current) => current[change.id]?.width === dimensions.width && current[change.id]?.height === dimensions.height ? current : { ...current, [change.id]: dimensions });
      }
      if (change.type === "select" && change.selected) {
        setSelectedId(change.id);
        onSelect(change.id);
      }
      if (change.type === "position" && change.position) {
        positions[change.id] = change.position;
        dragging = dragging || change.dragging === true;
      }
    }

    if (Object.keys(positions).length > 0) {
      onMove(positions, dragging);
    }
  }

  function isValidConnection(connection: Connection | { source: string | null; target: string | null; sourceHandle?: string | null }) {
    const source = nodes.find((node) => node.id === connection.source);
    const target = nodes.find((node) => node.id === connection.target);
    const handle = connection.sourceHandle ?? "";

    if (readOnly || !source || !target || source.id === target.id || target.type === "start" || !SOURCE_HANDLES[source.type].includes(handle)) {
      return false;
    }

    if (handle === "return" && target.type !== "approval") {
      return false;
    }

    return !edges.some((edge) => edge.source === source.id && edge.source_handle === handle);
  }

  return (
    <div className="flex h-full min-h-0 flex-col">
    <p className="shrink-0 border-b bg-card/80 px-4 py-2 text-[11px] text-muted-foreground">{readOnly ? "Workflow preview · Drag to pan, scroll to zoom" : "Connect outcomes on the canvas or in step settings. Drag the canvas to pan; scroll to zoom."}</p>
    <div className="min-h-0 flex-1"><ReactFlow
      nodes={flowNodes}
      edges={flowEdges}
      nodeTypes={workflowNodeTypes}
      onNodesChange={onNodesChange}
      onConnect={(connection) => {
        if (!connection.source || !connection.target || !connection.sourceHandle) {
          return;
        }

        onConnect({
          source: connection.source,
          target: connection.target,
          sourceHandle: connection.sourceHandle,
        });
      }}
      onNodeClick={(_event, node) => { setSelectedId(node.id); setMenu(null); onSelect(node.id); }}
      onPaneClick={() => { setSelectedId(null); setMenu(null); onSelect(null); }}
      onNodeContextMenu={(event, node) => {
        event.preventDefault();
        setSelectedId(node.id);
        onSelect(node.id);
        if (!readOnly) setMenu({ id: node.id, x: event.clientX, y: event.clientY });
      }}
      onMoveStart={() => setMenu(null)}
      onNodesDelete={(deleted) => onDeleteNodes(deleted.map((node) => node.id))}
      onEdgesDelete={(deleted) => onDeleteEdges(deleted.map((edge) => edge.id))}
      onDragOver={(event) => {
        event.preventDefault();
        event.dataTransfer.dropEffect = "move";
      }}
      onDrop={(event) => {
        event.preventDefault();
        if (readOnly) return;
        const type = event.dataTransfer.getData("application/flowmv-node") as NodeType;

        if (!Object.hasOwn(NODE_PRESENTATION, type)) {
          return;
        }

        onDropNode(type, screenToFlowPosition({ x: event.clientX, y: event.clientY }));
      }}
      isValidConnection={isValidConnection}
      nodesDraggable={!readOnly}
      nodesConnectable={!readOnly}
      elementsSelectable
      deleteKeyCode={readOnly ? null : ["Delete", "Backspace"]}
      fitView
      fitViewOptions={{ padding: 0.25, maxZoom: 1 }}
      snapToGrid
      snapGrid={[16, 16]}
      minZoom={0.2}
      maxZoom={1.8}
      defaultEdgeOptions={{ type: "smoothstep", style: { strokeWidth: 1.6 } }}
      className="bg-muted/30"
    >
      <Background gap={16} size={1} />
      <Panel position="top-right"><Button size="sm" variant="outline" className="bg-card" aria-pressed={showOverview} onClick={() => setShowOverview((current) => !current)}>Overview</Button></Panel>
      {menu ? <Panel position="top-right" className="rounded-lg border bg-card p-2 shadow-lg" role="group" aria-label="Step actions" onKeyDown={(event) => { if (event.key === "Escape") setMenu(null); }}>
        <p className="px-2 py-1 text-xs text-muted-foreground">{nodes.find((node) => node.id === menu.id)?.data.label || "Selected step"}</p>
        <Button autoFocus size="sm" variant="ghost" onClick={() => { onSelect(menu.id); setMenu(null); }}>Configure step</Button>
        <Button size="sm" variant="ghost" className="text-destructive" onClick={() => { onDeleteNodes([menu.id]); setMenu(null); }}>Delete step</Button>
        <Button size="sm" variant="ghost" onClick={() => setMenu(null)}>Close</Button>
      </Panel> : null}
      <Controls showInteractive={!readOnly} />
      {showOverview ? <MiniMap pannable zoomable ariaLabel="Workflow overview" style={{ width: 120, height: 80 }} nodeColor={(node) => NODE_PRESENTATION[node.type as NodeType]?.accent ?? "var(--muted)"} className="!hidden sm:!block" /> : null}
    </ReactFlow></div></div>
  );
}
