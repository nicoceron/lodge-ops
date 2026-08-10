import type { Metadata } from "next";
import Link from "next/link";
import { CheckCircle2, Layers3, ShieldCheck, Sparkles } from "lucide-react";
import { LoginForm } from "@/components/login-form";

export const metadata: Metadata = { title: "Sign in" };

const promises = [
  "One calendar for rooms, guides, activities, and equipment",
  "Tenant-isolated records with role-aware access",
  "Conflict prevention before a reservation is confirmed",
];

export default async function LoginPage({ searchParams }: { searchParams: Promise<{ next?: string }> }) {
  const { next } = await searchParams;
  return (
    <main className="min-h-screen bg-[var(--forest)] p-3 sm:p-6">
      <div className="mx-auto grid min-h-[calc(100vh-1.5rem)] max-w-6xl overflow-hidden rounded-[28px] bg-[var(--surface)] shadow-2xl sm:min-h-[calc(100vh-3rem)] lg:grid-cols-[1.05fr_0.95fr]">
        <section className="relative hidden overflow-hidden bg-[#173d2e] p-12 text-white lg:flex lg:flex-col">
          <div className="subtle-grid absolute inset-0 opacity-20" />
          <div className="relative flex items-center gap-3">
            <span className="grid size-11 place-items-center rounded-xl border border-white/15 bg-white/10 font-display text-2xl">L</span>
            <div><p className="font-display text-2xl">LodgeOps</p><p className="text-[10px] tracking-[0.18em] text-white/45 uppercase">Run with clarity</p></div>
          </div>
          <div className="relative my-auto max-w-xl">
            <span className="inline-flex items-center gap-2 rounded-full border border-white/12 bg-white/8 px-3 py-1.5 text-[11px] font-semibold text-white/70"><Sparkles className="size-3.5" /> Built for exceptional stays</span>
            <h1 className="mt-6 font-display text-6xl leading-[0.95] font-medium tracking-[-0.035em]">Every stay,<br />beautifully run.</h1>
            <p className="mt-6 max-w-md text-sm leading-6 text-white/58">A calm operating system for distinctive lodges and guided experiences—from first inquiry through the final folio.</p>
            <ul className="mt-9 space-y-4">
              {promises.map((promise) => <li key={promise} className="flex items-start gap-3 text-sm text-white/75"><CheckCircle2 className="mt-0.5 size-4 shrink-0 text-[#d7a56f]" />{promise}</li>)}
            </ul>
          </div>
          <div className="relative flex items-center gap-6 text-[10px] text-white/40"><span className="inline-flex items-center gap-1.5"><ShieldCheck className="size-3.5" /> Secure by design</span><span className="inline-flex items-center gap-1.5"><Layers3 className="size-3.5" /> Multi-property ready</span></div>
        </section>

        <section className="flex items-center justify-center px-6 py-12 sm:px-12">
          <div className="w-full max-w-md">
            <div className="mb-10 flex items-center gap-3 lg:hidden"><span className="grid size-10 place-items-center rounded-xl bg-[var(--forest)] font-display text-xl text-white">L</span><span className="font-display text-2xl">LodgeOps</span></div>
            <p className="text-[11px] font-bold tracking-[0.16em] text-[var(--amber)] uppercase">Welcome back</p>
            <h2 className="mt-3 font-display text-5xl leading-none font-medium tracking-[-0.03em]">Sign in to your lodge</h2>
            <p className="mt-4 text-sm leading-6 text-[var(--muted)]">Use your verified staff account. Your available lodges and permissions are resolved after sign-in.</p>
            <LoginForm nextPath={next} />
            <p className="mt-8 text-center text-xs text-[var(--muted)]">Need access? Ask a lodge owner or manager to invite you.</p>
            <p className="mt-3 text-center text-[10px] text-black/35"><Link href="/guest/g_7JvK2pQ9xR4mN8tW3cD6hF1sB5yE0uA" className="underline decoration-black/20 underline-offset-4 hover:text-[var(--forest)]">Preview the guest experience</Link></p>
          </div>
        </section>
      </div>
    </main>
  );
}
