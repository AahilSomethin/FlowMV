export const WORKSPACE_NAV = [
  { href: "/dashboard", label: "Overview" },
  { href: "/tasks", label: "My Tasks" },
  { href: "/requests", label: "Requests" },
  { href: "/workflows", label: "Workflows" },
  { href: "/analytics", label: "Analytics" },
  { href: "/team", label: "Team" },
  { href: "/settings", label: "Settings" },
] as const;

export type WorkspaceHref = (typeof WORKSPACE_NAV)[number]["href"];

const STATIC_PATHS = new Set<string>([
  ...WORKSPACE_NAV.map((item) => item.href),
  "/onboarding",
  "/invitations/accept",
]);

export function workspaceNavItem(pathname: string) {
  return (
    WORKSPACE_NAV.find((item) => item.href === pathname) ??
    WORKSPACE_NAV.find((item) => item.href !== "/dashboard" && pathname.startsWith(`${item.href}/`)) ??
    null
  );
}

function isWorkflowPath(path: string): boolean {
  return path === "/workflows" || /^\/workflows\/[0-9a-f-]{36}(?:\/edit)?$/i.test(path);
}

export function safeNextPath(value: string | null | undefined): string {
  if (!value || value.includes("\\") || value.includes("token=") || value.includes("#")) {
    return "/dashboard";
  }

  let url: URL;

  try {
    url = new URL(value, "http://localhost");
  } catch {
    return "/dashboard";
  }

  if (url.origin !== "http://localhost" || url.username || url.password) {
    return "/dashboard";
  }

  const path = url.pathname;
  const allowed = STATIC_PATHS.has(path) || path.startsWith("/verify-email/") || isWorkflowPath(path);

  if (!allowed || path.includes("//")) {
    return "/dashboard";
  }

  if (path === "/invitations/accept") {
    return path;
  }

  const hasToken = [...url.searchParams.keys()].some((key) => key.toLowerCase() === "token");

  if (hasToken) {
    return path;
  }

  const query = url.searchParams.toString();

  return query ? `${path}?${query}` : path;
}
