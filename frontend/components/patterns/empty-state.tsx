import type { ReactNode } from "react";

export function EmptyState({
  icon,
  title,
  description,
  action,
}: {
  icon?: ReactNode;
  title: string;
  description: string;
  action?: ReactNode;
}) {
  return (
    <div className="flex flex-col items-start gap-3 rounded-xl bg-card px-4 py-6 ring-1 ring-foreground/10 sm:px-6">
      {icon ? <div className="text-muted-foreground [&_svg]:size-5">{icon}</div> : null}
      <div className="flex max-w-xl flex-col gap-1">
        <h2 className="text-base font-medium">{title}</h2>
        <p className="text-sm leading-6 text-muted-foreground text-pretty">{description}</p>
      </div>
      {action}
    </div>
  );
}
