import type { ReactNode } from "react";
import { Badge } from "@/components/ui/badge";
import type { OrganizationRole } from "@/lib/api";
import { roleLabel } from "@/lib/organizations";

export function RoleBadge({ role }: { role: OrganizationRole }) {
  return <Badge variant="outline">{roleLabel(role)}</Badge>;
}

export function StatusBadge({
  children,
  tone = "neutral",
}: {
  children: ReactNode;
  tone?: "neutral" | "attention" | "danger";
}) {
  const variant = tone === "danger" ? "destructive" : tone === "attention" ? "outline" : "secondary";

  return <Badge variant={variant}>{children}</Badge>;
}
