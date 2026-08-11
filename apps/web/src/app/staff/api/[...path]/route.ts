import { cookies } from "next/headers";

const allowedRoots = new Set([
  "auth",
  "calendar",
  "dashboard",
  "deposits",
  "folio-lines",
  "guests",
  "operations",
  "payments",
  "programs",
  "properties",
  "reservations",
  "resource-blocks",
  "resources",
  "service-occurrences",
  "tasks",
]);

function safePath(parts: string[]) {
  if (!parts.length || !allowedRoots.has(parts[0]) || parts.some((part) => part === "." || part === "..")) return null;
  return parts.map(encodeURIComponent).join("/");
}

async function forward(request: Request, params: Promise<{ path: string[] }>) {
  const { path: parts } = await params;
  const path = safePath(parts);
  if (!path) return Response.json({ message: "Unknown staff API path." }, { status: 404 });

  const cookieStore = await cookies();
  const tenantId = cookieStore.get("lodgeops_tenant_id")?.value;
  if (!tenantId) return Response.json({ message: "No active lodge is selected." }, { status: 401 });

  const base = process.env.API_INTERNAL_URL ?? "http://localhost:8000";
  const incoming = new URL(request.url);
  const target = new URL(`/api/v1/${path}${incoming.search}`, base);
  const headers = new Headers();
  headers.set("Accept", "application/json");
  headers.set("Cookie", cookieStore.toString());
  headers.set("X-Tenant-ID", tenantId);
  headers.set("Origin", process.env.APP_ORIGIN ?? "http://localhost:3000");
  headers.set("Referer", `${(process.env.APP_ORIGIN ?? "http://localhost:3000").replace(/\/$/, "")}/`);
  const xsrf = cookieStore.get("XSRF-TOKEN")?.value;
  if (xsrf) headers.set("X-XSRF-TOKEN", decodeURIComponent(xsrf));
  for (const name of ["content-type", "idempotency-key", "if-match"]) {
    const value = request.headers.get(name);
    if (value) headers.set(name, value);
  }

  const method = request.method.toUpperCase();
  const body = method === "GET" || method === "HEAD" ? undefined : await request.arrayBuffer();
  const response = await fetch(target, { method, headers, body, cache: "no-store", redirect: "manual" });
  const responseHeaders = new Headers();
  responseHeaders.set("Content-Type", response.headers.get("Content-Type") ?? "application/json");
  const requestId = response.headers.get("X-Request-ID");
  if (requestId) responseHeaders.set("X-Request-ID", requestId);
  for (const cookie of response.headers.getSetCookie()) responseHeaders.append("Set-Cookie", cookie);

  return new Response(await response.arrayBuffer(), { status: response.status, headers: responseHeaders });
}

type Context = { params: Promise<{ path: string[] }> };
export async function GET(request: Request, context: Context) { return forward(request, context.params); }
export async function POST(request: Request, context: Context) { return forward(request, context.params); }
export async function PUT(request: Request, context: Context) { return forward(request, context.params); }
export async function PATCH(request: Request, context: Context) { return forward(request, context.params); }
export async function DELETE(request: Request, context: Context) { return forward(request, context.params); }
