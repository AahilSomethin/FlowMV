import type { ReactNode } from "react";

export function EmptyState({
  icon,
  title,
  description,
  action,
  align = "start",
}: {
  icon?: ReactNode;
  title: string;
  description: string;
  action?: ReactNode;
  align?: "start" | "center";
}) {
  const centered = align === "center";

  return (
    <div className={`flex flex-col gap-3 rounded-xl bg-card px-5 py-8 ring-1 ring-foreground/10 sm:px-6 ${centered ? "min-h-64 items-center justify-center text-center" : "items-start"}`}>
      {icon ? <div className="flex size-10 items-center justify-center rounded-full bg-surface-subtle text-muted-foreground [&_svg]:size-5">{icon}</div> : null}
      <div className={`flex max-w-xl flex-col gap-1 ${centered ? "items-center" : ""}`}>
        <h2 className="text-base font-medium">{title}</h2>
        <p className="text-sm leading-6 text-muted-foreground text-pretty">{description}</p>
      </div>
      {action}
    </div>
  );
}
