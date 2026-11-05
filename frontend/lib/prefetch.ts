import type { OrganizationRole } from "@/lib/api";

const publicShells = new Set([
  "/dashboard",
  "/tasks",
  "/requests",
  "/workflows",
  "/workflows/templates",
  "/connections",
  "/analytics",
  "/analytics/audit",
  "/team",
  "/settings",
]);
const providers = new Set(["ai", "email", "slack", "sms", "telegram"]);

export function safePrefetchPath(path: string): boolean {
  if (publicShells.has(path)) return true;
  if (path.startsWith("/connections/")) return providers.has(path.slice(13));
  return (
    /^\/(requests|tasks)\/[0-9a-f-]{36}$/i.test(path) ||
    /^\/workflows\/[0-9a-f-]{36}(\/edit)?$/i.test(path)
  );
}

export function likelyDestination(
  pathname: string,
  role?: OrganizationRole,
): string | null {
  if (pathname === "/dashboard")
    return role === "admin"
      ? "/workflows"
      : role === "manager"
        ? "/tasks"
        : "/requests";
  if (pathname === "/analytics") return "/analytics/audit";
  return null;
}

export function canSpeculate(
  connection?: { saveData?: boolean; effectiveType?: string },
  online = true,
): boolean {
  return (
    online &&
    !connection?.saveData &&
    !["slow-2g", "2g", "3g"].includes(connection?.effectiveType ?? "")
  );
}

/** Metadata only. Next owns route payloads; private API results never enter this budget. */
export class PrefetchBudget {
  private scope = "";
  private paths = new Map<string, number>();

  clear(): void {
    this.scope = "";
    this.paths.clear();
  }

  claim(scope: string, path: string, now = Date.now()): boolean {
    if (scope !== this.scope) {
      this.scope = scope;
      this.paths.clear();
    }
    for (const [key, at] of this.paths)
      if (now - at >= 60_000) this.paths.delete(key);
    if (
      !scope ||
      !safePrefetchPath(path) ||
      this.paths.has(path) ||
      this.paths.size >= 8
    )
      return false;
    this.paths.set(path, now);
    return true;
  }
}
