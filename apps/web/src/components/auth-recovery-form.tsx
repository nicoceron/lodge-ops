"use client";

import Link from "next/link";
import { useState, type FormEvent } from "react";
import { ArrowRight, CheckCircle2, LoaderCircle, Mail, ShieldCheck } from "lucide-react";

type Props =
  | { mode: "forgot" }
  | { mode: "reset"; token: string; email: string };

function browserCookie(name: string) {
  const prefix = `${name}=`;
  return document.cookie.split("; ").find((item) => item.startsWith(prefix))?.slice(prefix.length) ?? "";
}

export function AuthRecoveryForm(props: Props) {
  const [pending, setPending] = useState(false);
  const [message, setMessage] = useState("");
  const [complete, setComplete] = useState(false);
  const apiUrl = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPending(true);
    setMessage("");
    const form = new FormData(event.currentTarget);
    const payload = props.mode === "forgot"
      ? { email: form.get("email") }
      : {
          token: props.token,
          email: props.email,
          password: form.get("password"),
          password_confirmation: form.get("password_confirmation"),
        };
    try {
      await fetch(`${apiUrl}/sanctum/csrf-cookie`, { credentials: "include" });
      const response = await fetch(`${apiUrl}/api/v1/auth/${props.mode === "forgot" ? "forgot-password" : "reset-password"}`, {
        method: "POST",
        credentials: "include",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          "X-XSRF-TOKEN": decodeURIComponent(browserCookie("XSRF-TOKEN")),
        },
        body: JSON.stringify(payload),
      });
      const body = await response.json().catch(() => null) as { message?: string } | null;
      if (!response.ok) throw new Error(body?.message ?? "We could not complete that request.");
      setComplete(true);
      setMessage(body?.message ?? "Done.");
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "We could not complete that request.");
    } finally {
      setPending(false);
    }
  }

  if (complete) {
    return <div className="mt-8 rounded-2xl border border-[var(--forest)]/15 bg-[var(--forest-soft)] p-5"><CheckCircle2 className="size-6 text-[var(--forest)]" /><p className="mt-3 text-sm font-bold text-[var(--forest)]">{message}</p><Link href="/login" className="mt-5 inline-flex items-center gap-2 text-xs font-bold text-[var(--forest)] underline underline-offset-4">Return to sign in <ArrowRight className="size-3.5" /></Link></div>;
  }

  return (
    <form onSubmit={submit} className="mt-8 space-y-5">
      {props.mode === "forgot" ? (
        <label className="block"><span className="text-xs font-bold">Email address</span><span className="relative mt-2 block"><Mail aria-hidden="true" className="absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-black/35" /><input name="email" type="email" autoComplete="email" required className="h-12 w-full rounded-xl border border-black/10 bg-white pl-11 pr-4 text-sm shadow-sm" /></span></label>
      ) : (
        <>
          <div className="rounded-xl border border-black/8 bg-black/[0.025] px-4 py-3 text-xs text-[var(--muted)]"><strong className="text-[var(--foreground)]">Account:</strong> {props.email}</div>
          <label className="block"><span className="text-xs font-bold">New password</span><input name="password" type="password" autoComplete="new-password" minLength={12} required className="mt-2 h-12 w-full rounded-xl border border-black/10 bg-white px-4 text-sm shadow-sm" /></label>
          <label className="block"><span className="text-xs font-bold">Confirm new password</span><input name="password_confirmation" type="password" autoComplete="new-password" minLength={12} required className="mt-2 h-12 w-full rounded-xl border border-black/10 bg-white px-4 text-sm shadow-sm" /></label>
        </>
      )}
      {message ? <div role="alert" className="rounded-xl border border-[var(--red)]/15 bg-[var(--red-soft)] px-4 py-3 text-xs text-[var(--red)]">{message}</div> : null}
      <button type="submit" disabled={pending} className="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-[var(--forest)] text-sm font-bold text-white disabled:opacity-65">{pending ? <LoaderCircle className="size-4 animate-spin" /> : <ShieldCheck className="size-4" />}{pending ? "Working…" : props.mode === "forgot" ? "Send reset link" : "Reset password"}</button>
      <p className="text-center text-xs"><Link href="/login" className="font-bold text-[var(--forest)] underline underline-offset-4">Back to sign in</Link></p>
    </form>
  );
}
