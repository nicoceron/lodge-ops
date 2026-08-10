export class StaffMutationError extends Error {
  constructor(message: string, public readonly errors: Record<string, string[]> = {}) {
    super(message);
    this.name = "StaffMutationError";
  }
}

export async function staffMutation<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set("Accept", "application/json");
  if (init.body && !(init.body instanceof FormData)) headers.set("Content-Type", "application/json");
  if (init.method && init.method !== "GET" && !headers.has("Idempotency-Key")) {
    headers.set("Idempotency-Key", crypto.randomUUID());
  }
  const response = await fetch(`/staff/api/${path.replace(/^\//, "")}`, { ...init, headers });
  const body = await response.json().catch(() => null) as { message?: string; errors?: Record<string, string[]> } | null;
  if (!response.ok) {
    throw new StaffMutationError(body?.message ?? "The lodge could not save this change.", body?.errors);
  }
  return body as T;
}
