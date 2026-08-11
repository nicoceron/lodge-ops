"use client";

import { useRouter } from "next/navigation";
import type { StaffTenant } from "@/data/staff-api";

export function TenantSwitcher({ tenants, selectedId, variant = "sidebar" }: { tenants: StaffTenant[]; selectedId: string; variant?: "sidebar" | "header" }) {
  const router = useRouter();
  return (
    <label className="block">
      <span className="sr-only">Active lodge</span>
      <select
        aria-label="Active lodge"
        value={selectedId}
        onChange={(event) => {
          document.cookie = `lodgeops_tenant_id=${encodeURIComponent(event.target.value)}; Path=/; Max-Age=2592000; SameSite=Lax`;
          router.refresh();
        }}
        className={variant === "sidebar" ? "w-full rounded-lg border border-white/10 bg-white/10 px-3 py-2 text-xs font-semibold text-white outline-none" : "h-10 max-w-36 rounded-xl border border-black/8 bg-white/70 px-2 text-xs font-semibold text-[var(--foreground)] shadow-sm"}
      >
        {tenants.map((tenant) => <option key={tenant.id} value={tenant.id} className="text-black">{tenant.name}</option>)}
      </select>
    </label>
  );
}
