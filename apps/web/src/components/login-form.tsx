"use client";

import { useState, type FormEvent } from "react";
import { ArrowRight, Eye, EyeOff, LoaderCircle, LockKeyhole, Mail } from "lucide-react";
import { useRouter } from "next/navigation";

type AuthPayload = {
  data: {
    tenants: Array<{ id: string; name: string; slug: string }>;
  };
};

function cookie(name: string) {
  const prefix = `${name}=`;
  return document.cookie.split("; ").find((item) => item.startsWith(prefix))?.slice(prefix.length) ?? "";
}

export function LoginForm() {
  const router = useRouter();
  const [showPassword, setShowPassword] = useState(false);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState("");
  const apiUrl = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
  const demoMode = process.env.NEXT_PUBLIC_DEMO_MODE === "true";

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPending(true);
    setError("");
    const form = new FormData(event.currentTarget);

    try {
      await fetch(`${apiUrl}/sanctum/csrf-cookie`, { credentials: "include" });
      const response = await fetch(`${apiUrl}/api/v1/auth/login`, {
        method: "POST",
        credentials: "include",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          "X-XSRF-TOKEN": decodeURIComponent(cookie("XSRF-TOKEN")),
        },
        body: JSON.stringify({
          email: form.get("email"),
          password: form.get("password"),
          remember: form.get("remember") === "on",
        }),
      });
      const body = (await response.json().catch(() => null)) as AuthPayload | { message?: string } | null;
      if (!response.ok) throw new Error(body && "message" in body ? body.message : "Sign-in failed. Check your details and try again.");

      const tenant = (body as AuthPayload).data.tenants[0];
      if (!tenant) throw new Error("Your account does not have an active lodge membership.");
      document.cookie = `lodgeops_tenant_id=${encodeURIComponent(tenant.id)}; Path=/; Max-Age=2592000; SameSite=Lax`;
      router.push("/");
      router.refresh();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Sign-in failed. Please try again.");
      setPending(false);
    }
  }

  return (
    <form onSubmit={submit} className="mt-8 space-y-5" noValidate>
      <label className="block"><span className="text-xs font-bold">Email address</span><span className="relative mt-2 block"><Mail aria-hidden="true" className="absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-black/35" /><input name="email" type="email" autoComplete="email" required defaultValue={demoMode ? "admin@example.com" : undefined} className="h-12 w-full rounded-xl border border-black/10 bg-white pl-11 pr-4 text-sm shadow-sm" /></span></label>
      <div className="block"><label htmlFor="staff-password" className="text-xs font-bold">Password</label><span className="relative mt-2 block"><LockKeyhole aria-hidden="true" className="absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-black/35" /><input id="staff-password" name="password" type={showPassword ? "text" : "password"} autoComplete="current-password" required defaultValue={demoMode ? "password" : undefined} className="h-12 w-full rounded-xl border border-black/10 bg-white pl-11 pr-12 text-sm shadow-sm" /><button type="button" aria-label={showPassword ? "Hide password" : "Show password"} onClick={() => setShowPassword((value) => !value)} className="absolute right-3 top-1/2 grid size-8 -translate-y-1/2 place-items-center text-black/35">{showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}</button></span></div>
      <label className="flex items-center gap-2.5 text-xs text-[var(--muted)]"><input name="remember" type="checkbox" className="size-4 rounded border-black/15 accent-[var(--forest)]" />Keep me signed in on this device</label>
      {error ? <div role="alert" className="rounded-xl border border-[var(--red)]/15 bg-[var(--red-soft)] px-4 py-3 text-xs leading-5 text-[var(--red)]">{error}</div> : null}
      <button type="submit" disabled={pending} className="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-[var(--forest)] text-sm font-bold text-white shadow-[0_10px_24px_rgb(23_61_46/20%)] disabled:opacity-65">{pending ? <LoaderCircle aria-hidden="true" className="size-4 animate-spin" /> : <ArrowRight aria-hidden="true" className="size-4" />}{pending ? "Signing in…" : "Sign in securely"}</button>
      {demoMode ? <p className="text-center text-[10px] leading-4 text-black/35">Development access is pre-filled after running the deterministic demo seed.</p> : null}
    </form>
  );
}
