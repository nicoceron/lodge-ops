"use client";

import { useState, type FormEvent } from "react";
import { ArrowRight, Eye, EyeOff, KeyRound, LoaderCircle, LockKeyhole, Mail, ShieldCheck } from "lucide-react";
import { useRouter } from "next/navigation";
import Link from "next/link";

type AuthPayload = {
  data: {
    tenants: Array<{ id: string; name: string; slug: string }>;
  };
};

type AuthErrorPayload = { message?: string; mfa_required?: boolean };

function cookie(name: string) {
  const prefix = `${name}=`;
  return document.cookie.split("; ").find((item) => item.startsWith(prefix))?.slice(prefix.length) ?? "";
}

export function LoginForm({ nextPath = "/" }: { nextPath?: string }) {
  const router = useRouter();
  const [showPassword, setShowPassword] = useState(false);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState("");
  const [mfaRequired, setMfaRequired] = useState(false);
  const [useRecoveryCode, setUseRecoveryCode] = useState(false);
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
          mfa_code: form.get("mfa_code") || undefined,
          recovery_code: form.get("recovery_code") || undefined,
        }),
      });
      const body = (await response.json().catch(() => null)) as AuthPayload | AuthErrorPayload | null;
      if (!response.ok && body && "mfa_required" in body && body.mfa_required) {
        setMfaRequired(true);
        setError("");
        setPending(false);
        return;
      }
      if (!response.ok) throw new Error(body && "message" in body ? body.message : "Sign-in failed. Check your details and try again.");

      const tenant = (body as AuthPayload).data.tenants[0];
      if (!tenant) throw new Error("Your account does not have an active lodge membership.");
      document.cookie = `lodgeops_tenant_id=${encodeURIComponent(tenant.id)}; Path=/; Max-Age=2592000; SameSite=Lax`;
      router.push(nextPath.startsWith("/") && !nextPath.startsWith("//") ? nextPath : "/");
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
      {mfaRequired ? (
        <div className="rounded-2xl border border-[var(--forest)]/15 bg-[var(--forest-soft)]/55 p-4">
          <div className="flex gap-3"><ShieldCheck aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-[var(--forest)]" /><div><p className="text-xs font-bold text-[var(--forest)]">Confirm it’s you</p><p className="mt-1 text-[11px] leading-4 text-[#536b5d]">Your password is correct. Complete the second step to open the lodge workspace.</p></div></div>
          <label className="mt-4 block"><span className="text-xs font-bold">{useRecoveryCode ? "Recovery code" : "Authenticator code"}</span><span className="relative mt-2 block"><KeyRound aria-hidden="true" className="absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-black/35" /><input name={useRecoveryCode ? "recovery_code" : "mfa_code"} inputMode={useRecoveryCode ? "text" : "numeric"} pattern={useRecoveryCode ? undefined : "[0-9]{6}"} autoComplete="one-time-code" required className="h-12 w-full rounded-xl border border-black/10 bg-white pl-11 pr-4 font-mono text-sm tracking-[0.18em] shadow-sm" /></span></label>
          <button type="button" onClick={() => setUseRecoveryCode((value) => !value)} className="mt-3 text-[11px] font-bold text-[var(--forest)] underline underline-offset-2">{useRecoveryCode ? "Use authenticator code" : "Use a recovery code"}</button>
        </div>
      ) : null}
      <label className="flex items-center gap-2.5 text-xs text-[var(--muted)]"><input name="remember" type="checkbox" className="size-4 rounded border-black/15 accent-[var(--forest)]" />Keep me signed in on this device</label>
      <div className="text-right"><Link href="/forgot-password" className="text-[11px] font-bold text-[var(--forest)] underline underline-offset-4">Forgot your password?</Link></div>
      {error ? <div role="alert" className="rounded-xl border border-[var(--red)]/15 bg-[var(--red-soft)] px-4 py-3 text-xs leading-5 text-[var(--red)]">{error}</div> : null}
      <button type="submit" disabled={pending} className="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-[var(--forest)] text-sm font-bold text-white shadow-[0_10px_24px_rgb(23_61_46/20%)] disabled:opacity-65">{pending ? <LoaderCircle aria-hidden="true" className="size-4 animate-spin" /> : <ArrowRight aria-hidden="true" className="size-4" />}{pending ? "Signing in…" : mfaRequired ? "Verify and sign in" : "Sign in securely"}</button>
      {demoMode ? <p className="text-center text-[10px] leading-4 text-black/35">Development access is pre-filled after running the deterministic demo seed.</p> : null}
    </form>
  );
}
