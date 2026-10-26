"use client";

import type { NodeType } from "@/lib/workflows/types";
import { NODE_COPY } from "@/lib/workflows/types";

const ORDER: NodeType[] = ["start", "approval", "condition", "revision", "end"];

export function NodePalette({
  startDisabled,
  onAdd,
}: {
  startDisabled: boolean;
  onAdd: (type: NodeType) => void;
}) {
  return (
    <div className="flex flex-col gap-2">
      <div>
        <h2 className="text-sm font-medium">Nodes</h2>
        <p className="text-xs leading-5 text-muted-foreground">Drag a node onto the canvas, or add it with the button.</p>
      </div>
      {ORDER.map((type) => {
        const copy = NODE_COPY[type];
        const disabled = type === "start" && startDisabled;

        return (
          <div key={type} className="rounded-lg bg-card p-2 ring-1 ring-foreground/10" data-node-type={type}>
            <button
              type="button"
              draggable={!disabled}
              disabled={disabled}
              onDragStart={(event) => {
                event.dataTransfer.setData("application/flowmv-node", type);
                event.dataTransfer.effectAllowed = "move";
              }}
              onClick={() => onAdd(type)}
              className="flex w-full flex-col gap-1 rounded-md px-1 py-1 text-left outline-none hover:bg-muted focus-visible:ring-3 focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50"
            >
              <span className="text-sm font-medium text-[var(--node-accent)]" style={{ ["--node-accent" as string]: `var(--node-${type})` }}>
                {copy.title}
              </span>
              <span className="text-xs leading-5 text-muted-foreground">{copy.description}</span>
            </button>
          </div>
        );
      })}
    </div>
  );
}
