import { NextResponse, type NextRequest } from "next/server";

const SESSION_COOKIE = "flowmv-session";

function requiresSession(pathname: string): boolean {
  return ["/dashboard", "/onboarding", "/team"].some(
    (path) => pathname === path || pathname.startsWith(`${path}/`),
  );
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
  matcher: ["/dashboard/:path*", "/onboarding/:path*", "/team/:path*", "/dashboard", "/onboarding", "/team"],
};
