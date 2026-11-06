import type { ReactNode } from "react";

export function FilterBar({ label = "Filters", children }: { label?: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-2 rounded-xl bg-surface-subtle p-3 ring-1 ring-foreground/10 sm:flex-row sm:flex-wrap sm:items-center" role="group" aria-label={label}>
      {children}
    </div>
  );
}
