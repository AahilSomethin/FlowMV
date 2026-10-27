"use client";

import { useMemo, useState } from "react";
import { Input } from "@/components/ui/input";
import { NODE_PRESENTATION, nodeDescription, nodeTitle, paletteCategories } from "@/lib/workflows/node-registry";
import type { NodeType } from "@/lib/workflows/types";

export function NodePalette({
  startDisabled,
  onAdd,
}: {
  startDisabled: boolean;
  onAdd: (type: NodeType) => void;
}) {
  const [query, setQuery] = useState("");
  const normalized = query.trim().toLowerCase();

  const categories = useMemo(() => {
    return paletteCategories().map((category) => ({
      ...category,
      types: category.types.filter((type) => {
        if (!normalized) {
          return true;
        }

        const title = nodeTitle(type).toLowerCase();
        const description = nodeDescription(type).toLowerCase();

        return title.includes(normalized) || description.includes(normalized) || type.includes(normalized);
      }),
    })).filter((category) => category.types.length > 0);
  }, [normalized]);

  return (
    <div className="flex flex-col gap-3">
      <div>
        <h2 className="text-sm font-semibold">Build your workflow</h2>
        <p className="mt-1 text-xs leading-5 text-muted-foreground">Add steps, then connect their outcomes. Click a step to configure it.</p>
      </div>
      <Input
        aria-label="Search nodes"
        placeholder="Search nodes…"
        value={query}
        onChange={(event) => setQuery(event.target.value)}
      />
      {categories.length === 0 ? (
        <p className="text-sm text-muted-foreground">No nodes match your search.</p>
      ) : (
        categories.map((category) => (
          <div key={category.id} className="flex flex-col gap-2">
            <h3 className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{category.label}</h3>
            {category.types.map((type) => {
              const disabled = type === "start" && startDisabled;
              const Icon = NODE_PRESENTATION[type].icon;

              return (
                <div key={type} className="rounded-xl border bg-card p-1 transition-colors hover:border-primary/40" data-node-type={type}>
                  <button
                    type="button"
                    draggable={!disabled}
                    disabled={disabled}
                    title={disabled ? "A workflow has one Start step" : `Add ${nodeTitle(type)}`}
                    onDragStart={(event) => {
                      event.dataTransfer.setData("application/flowmv-node", type);
                      event.dataTransfer.effectAllowed = "move";
                    }}
                    onClick={() => onAdd(type)}
                    className="flex w-full flex-col gap-1 rounded-lg p-2 text-left outline-none hover:bg-muted/50 focus-visible:ring-3 focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    <span
                      className="text-sm font-medium text-[var(--node-accent)]"
                      style={{ ["--node-accent" as string]: `var(--node-${type})` }}
                    >
                      <Icon className="mr-2 inline size-4" aria-hidden="true" />{nodeTitle(type)}
                    </span>
                    <span className="text-xs leading-5 text-muted-foreground">{nodeDescription(type)}</span>
                  </button>
                </div>
              );
            })}
          </div>
        ))
      )}
    </div>
  );
}
