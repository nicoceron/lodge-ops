import { NextResponse, type NextRequest } from "next/server";

const publicPrefixes = [
  "/login",
  "/forgot-password",
  "/reset-password",
  "/guest",
  "/_next",
  "/favicon.ico",
];

export function proxy(request: NextRequest) {
  if (process.env.NEXT_PUBLIC_DEMO_MODE === "true") return NextResponse.next();
  const { pathname, search } = request.nextUrl;
  if (publicPrefixes.some((prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`))) {
    return NextResponse.next();
  }

  const sessionCookie = process.env.SESSION_COOKIE_NAME ?? "laravel-session";
  if (!request.cookies.has(sessionCookie) || !request.cookies.has("lodgeops_tenant_id")) {
    const login = new URL("/login", request.url);
    if (pathname !== "/") login.searchParams.set("next", `${pathname}${search}`);
    return NextResponse.redirect(login);
  }

  return NextResponse.next();
}

export const config = {
  matcher: ["/((?!_next/static|_next/image|.*\\.(?:svg|png|jpg|jpeg|gif|webp|ico|woff2)$).*)"],
};
