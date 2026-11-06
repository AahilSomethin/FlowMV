export const WORKSPACE_NAV = [
  { href: "/dashboard", label: "Overview", group: "Work" },
  { href: "/tasks", label: "My Tasks", group: "Work" },
  { href: "/requests", label: "Requests", group: "Work" },
  { href: "/workflows", label: "Workflows", group: "Build" },
  { href: "/connections", label: "Connections", group: "Build" },
  { href: "/analytics", label: "Analytics", group: "Insights" },
  { href: "/team", label: "Team", group: "Organization" },
  { href: "/settings", label: "Settings", group: "Organization" },
] as const;

export const NAV_GROUPS = ["Work", "Build", "Insights", "Organization"] as const;

export type WorkspaceHref = (typeof WORKSPACE_NAV)[number]["href"];

export function canLeaveCurrentPage(): boolean {
  return window.dispatchEvent(new Event("flowmv:before-navigate", { cancelable: true }));
}

const STATIC_PATHS = new Set<string>([
  ...WORKSPACE_NAV.map((item) => item.href),
  "/workflows/templates",
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

function isExecutionPath(path: string): boolean {
  return path === "/requests/new" || /^\/(?:requests|tasks)\/[0-9a-f-]{36}$/i.test(path);
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
  const allowed = STATIC_PATHS.has(path) || path.startsWith("/verify-email/") || isWorkflowPath(path) || isExecutionPath(path);

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
