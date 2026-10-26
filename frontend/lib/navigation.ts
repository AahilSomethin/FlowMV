const STATIC_PATHS = new Set(["/dashboard", "/onboarding", "/team", "/invitations/accept"]);

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
  const allowed = STATIC_PATHS.has(path) || path.startsWith("/verify-email/");

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
