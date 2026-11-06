import type { ReactNode } from "react";
import { cn } from "cn";
import { Badge } from "@/components/ui/badge";
import type { OrganizationRole } from "@/lib/api";
import { roleLabel } from "@/lib/organizations";

export function RoleBadge({ role }: { role: OrganizationRole }) {
  return <Badge variant="outline">{roleLabel(role)}</Badge>;
}

const tones = {
  neutral: "border-transparent bg-surface-subtle text-foreground",
  attention: "border-transparent bg-status-warning-soft text-status-warning",
  danger: "border-transparent bg-status-danger-soft text-destructive",
  success: "border-transparent bg-status-success-soft text-status-success",
  info: "border-transparent bg-status-info-soft text-status-info",
} as const;

export function StatusBadge({
  children,
  tone = "neutral",
  className,
}: {
  children: ReactNode;
  tone?: keyof typeof tones;
  className?: string;
}) {
  return (
    <Badge variant="outline" className={cn(tones[tone], "shrink-0", className)}>
      <span className="size-1.5 rounded-full bg-current" aria-hidden="true" />
      {children}
    </Badge>
  );
}
