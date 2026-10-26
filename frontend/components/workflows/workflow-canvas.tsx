"use client";

import { useEffect } from "react";
import {
  Background,
  Controls,
  MiniMap,
  ReactFlow,
  ReactFlowProvider,
  useReactFlow,
  type Connection,
  type NodeChange,
} from "@xyflow/react";
import "@xyflow/react/dist/style.css";
import type { NodeType, WorkflowEdge, WorkflowNode, WorkflowViolation } from "@/lib/workflows/types";
import { SOURCE_HANDLES } from "@/lib/workflows/types";
import { workflowNodeTypes, type FlowNode } from "@/components/workflows/workflow-node";

type CanvasProps = {
  nodes: WorkflowNode[];
  edges: WorkflowEdge[];
  violations: WorkflowViolation[];
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
  readOnly = false,
  onSelect,
  onMove,
  onConnect,
  onDeleteNodes,
  onDeleteEdges,
  onDropNode,
}: CanvasProps) {
  const { screenToFlowPosition, fitView } = useReactFlow();
  const invalid = new Set(violations.map((item) => item.node_id).filter((id): id is string => Boolean(id)));
  const flowNodes: FlowNode[] = nodes.map((node) => ({
    id: node.id,
    type: node.type,
    position: node.position,
    data: { ...node.data, invalid: invalid.has(node.id) },
    draggable: !readOnly,
    connectable: !readOnly,
  }));
  const flowEdges = edges.map((edge) => ({
    id: edge.id,
    source: edge.source,
    target: edge.target,
    sourceHandle: edge.source_handle,
    targetHandle: edge.target_handle,
    label: edge.source_handle === "out" ? undefined : edge.source_handle,
  }));

  useEffect(() => {
    const frame = requestAnimationFrame(() => {
      void fitView({ padding: 0.2, duration: 0 });
    });

    return () => cancelAnimationFrame(frame);
  }, [fitView, nodes.length]);

  function onNodesChange(changes: NodeChange<FlowNode>[]) {
    const positions: Record<string, { x: number; y: number }> = {};
    let dragging = false;

    for (const change of changes) {
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

    if (!source || !target || target.type === "start" || !SOURCE_HANDLES[source.type].includes(handle)) {
      return false;
    }

    if (handle === "return" && target.type !== "approval") {
      return false;
    }

    return !edges.some((edge) => edge.source === source.id && edge.source_handle === handle);
  }

  return (
    <ReactFlow
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
      onNodeClick={(_event, node) => onSelect(node.id)}
      onPaneClick={() => onSelect(null)}
      onNodesDelete={(deleted) => onDeleteNodes(deleted.map((node) => node.id))}
      onEdgesDelete={(deleted) => onDeleteEdges(deleted.map((edge) => edge.id))}
      onDragOver={(event) => {
        event.preventDefault();
        event.dataTransfer.dropEffect = "move";
      }}
      onDrop={(event) => {
        event.preventDefault();
        const type = event.dataTransfer.getData("application/flowmv-node") as NodeType;

        if (!type) {
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
      className="bg-background"
    >
      <Background />
      <Controls showInteractive={!readOnly} />
      <MiniMap pannable zoomable ariaLabel="Workflow overview" />
    </ReactFlow>
  );
}
