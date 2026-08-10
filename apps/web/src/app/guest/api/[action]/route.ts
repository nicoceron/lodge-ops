import { forwardGuestPortalRequest } from "@/data/guest-api";

const actions = {
  "pre-arrival": { method: "PUT", path: "pre-arrival" },
  waiver: { method: "POST", path: "waiver" },
  "payment-evidence": { method: "POST", path: "payment-evidence" },
  survey: { method: "POST", path: "survey" },
} as const;

async function handle(request: Request, params: Promise<{ action: string }>, method: "POST" | "PUT") {
  const { action } = await params;
  const target = actions[action as keyof typeof actions];
  if (!target) return Response.json({ message: "Unknown guest portal action." }, { status: 404 });
  if (target.method !== method) return Response.json({ message: "Method not allowed." }, { status: 405 });

  const multipart = action === "payment-evidence";
  const body = multipart
    ? await request.formData().catch(() => null)
    : await request.json().catch(() => null);
  if (!body) return Response.json({ message: "A request body is required." }, { status: 400 });

  return forwardGuestPortalRequest(target.path, method, body, multipart);
}

export async function POST(request: Request, { params }: { params: Promise<{ action: string }> }) {
  return handle(request, params, "POST");
}

export async function PUT(request: Request, { params }: { params: Promise<{ action: string }> }) {
  return handle(request, params, "PUT");
}
