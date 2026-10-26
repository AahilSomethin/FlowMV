import { NextResponse, type NextRequest } from "next/server";
import { WORKSPACE_NAV } from "@/lib/navigation";

const SESSION_COOKIE = "flowmv-session";

const PROTECTED_PATHS = ["/onboarding", ...WORKSPACE_NAV.map((item) => item.href)];

function requiresSession(pathname: string): boolean {
  return PROTECTED_PATHS.some((path) => pathname === path || pathname.startsWith(`${path}/`));
}

export function proxy(request: NextRequest) {
  const { pathname } = request.nextUrl;

  if (requiresSession(pathname) && !request.cookies.has(SESSION_COOKIE)) {
    const url = request.nextUrl.clone();
    url.pathname = "/login";

    return NextResponse.redirect(url);
  }

  return NextResponse.next();
}

export const config = {
  matcher: [
    "/dashboard/:path*",
    "/onboarding/:path*",
    "/team/:path*",
    "/tasks/:path*",
    "/requests/:path*",
    "/workflows/:path*",
    "/analytics/:path*",
    "/settings/:path*",
    "/dashboard",
    "/onboarding",
    "/team",
    "/tasks",
    "/requests",
    "/workflows",
    "/analytics",
    "/settings",
  ],
};
