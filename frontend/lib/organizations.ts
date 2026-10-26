import type { OrganizationRole } from "@/lib/api";

export type Member = {
  uuid: string;
  role: OrganizationRole;
  name: string;
  email: string;
  created_at: string | null;
};

export type Invitation = {
  uuid: string;
  email: string;
  role: OrganizationRole;
  expires_at: string | null;
  created_at: string | null;
};

export function roleLabel(role: OrganizationRole): string {
  if (role === "admin") {
    return "Admin";
  }

  if (role === "manager") {
    return "Manager";
  }

  return "Employee";
}
