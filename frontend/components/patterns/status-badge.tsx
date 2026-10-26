import type { ReactNode } from "react";
import { Badge } from "@/components/ui/badge";
import type { OrganizationRole } from "@/lib/api";
import { roleLabel } from "@/lib/organizations";

export function RoleBadge({ role }: { role: OrganizationRole }) {
  return <Badge variant="outline">{roleLabel(role)}</Badge>;
}

export function StatusBadge({ children }: { children: ReactNode }) {
  return <Badge variant="secondary">{children}</Badge>;
}
