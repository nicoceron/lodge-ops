import type { Metadata } from "next";
import { AuthRecoveryForm } from "@/components/auth-recovery-form";

export const metadata: Metadata = { title: "Reset password" };

export default function ForgotPasswordPage() {
  return <main className="grid min-h-screen place-items-center bg-[var(--forest)] p-4"><section className="w-full max-w-lg rounded-[28px] bg-[var(--surface)] p-8 shadow-2xl sm:p-12"><span className="grid size-11 place-items-center rounded-xl bg-[var(--forest)] font-display text-2xl text-white">L</span><p className="mt-8 text-[11px] font-bold tracking-[0.16em] text-[var(--amber)] uppercase">Account recovery</p><h1 className="mt-3 font-display text-5xl font-medium tracking-[-0.03em]">Reset your password</h1><p className="mt-4 text-sm leading-6 text-[var(--muted)]">Enter your verified staff email. We return the same response whether or not an account exists.</p><AuthRecoveryForm mode="forgot" /></section></main>;
}
