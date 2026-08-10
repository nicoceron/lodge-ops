"use client";

import { useState } from "react";
import { LogOut, LoaderCircle } from "lucide-react";
import { useRouter } from "next/navigation";
import { staffMutation } from "@/data/staff-client";

export function LogoutButton({ compact = false }: { compact?: boolean }) {
  const router = useRouter();
  const [pending, setPending] = useState(false);

  async function logout() {
    setPending(true);
    try {
      await staffMutation("auth/logout", { method: "POST" });
    } finally {
      document.cookie = "lodgeops_tenant_id=; Path=/; Max-Age=0; SameSite=Lax";
      router.replace("/login");
      router.refresh();
    }
  }

  return (
    <button type="button" onClick={logout} disabled={pending} aria-label={compact ? "Sign out" : undefined} className={compact ? "grid size-10 place-items-center rounded-xl border border-black/8 bg-white/70 text-[var(--muted)] shadow-sm disabled:opacity-60" : "flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-xs font-semibold text-white/65 hover:bg-white/8 hover:text-white disabled:opacity-60"}>
      {pending ? <LoaderCircle aria-hidden="true" className="size-4 animate-spin" /> : <LogOut aria-hidden="true" className="size-4" />}
      {compact ? null : pending ? "Signing out…" : "Sign out"}
    </button>
  );
}
