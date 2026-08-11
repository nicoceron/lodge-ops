import type { Metadata } from "next";
import { cookies } from "next/headers";
import { ArrowUpRight, BadgeCheck, Building2, CalendarCog, CircleDollarSign, MailCheck, ShieldCheck, UsersRound } from "lucide-react";
import { AppShell } from "@/components/app-shell";
import { requireStaffUser, selectedTenant } from "@/data/staff-api";

export const metadata: Metadata = { title: "Manage" };

const sections = [
  { title: "Properties & resources", description: "Rooms, guides, horses, boats, vehicles, capacities, and maintenance.", icon: Building2, path: "properties" },
  { title: "Programs & pricing", description: "Itineraries, requirements, seasons, program prices, and operational templates.", icon: CircleDollarSign, path: "programs" },
  { title: "People & access", description: "Memberships, roles, invitations, property scopes, and sensitive-field access.", icon: UsersRound, path: "team-members" },
  { title: "Automation", description: "Task templates, arrival milestones, notifications, and no-code rules.", icon: CalendarCog, path: "automation-rules" },
  { title: "Messages & documents", description: "Versioned templates, sender identities, PDFs, waivers, and guest forms.", icon: MailCheck, path: "message-templates" },
  { title: "Security & audit", description: "MFA, sessions, exports, support access, and account security.", icon: ShieldCheck, path: "" },
] as const;

export default async function SettingsPage() {
  const user = await requireStaffUser();
  const selectedId = (await cookies()).get("lodgeops_tenant_id")?.value;
  const activeTenant = selectedTenant(user, selectedId);
  const adminBase = (process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000").replace(/\/$/, "");
  const workspacePath = activeTenant?.slug ? `/admin/workspace/${encodeURIComponent(activeTenant.slug)}` : "/admin";
  return (
    <AppShell eyebrow="Tenant administration" title={`Manage ${activeTenant?.name ?? "active lodge"}`} description="The essentials first, with advanced configuration available when the operation needs it.">
      <div className="mb-5 flex items-start gap-3 rounded-2xl border border-[var(--forest)]/15 bg-[var(--forest-soft)] p-4"><BadgeCheck aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-[var(--forest)]" /><div><p className="text-xs font-bold text-[var(--forest)]">Secure administration workspace</p><p className="mt-1 text-[10px] leading-4 text-[#536b5d]">Configuration links open the active tenant in the role-aware Filament panel. No placeholder counts or connection claims are shown here.</p></div></div>
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {sections.map((section) => { const Icon = section.icon; return (
          <a key={section.title} href={`${adminBase}${workspacePath}${section.path ? `/${section.path}` : ""}`} className="surface-card group rounded-2xl p-5 transition-transform hover:-translate-y-0.5">
            <div className="flex items-start justify-between"><span className="grid size-11 place-items-center rounded-xl bg-[var(--forest-soft)] text-[var(--forest)]"><Icon aria-hidden="true" className="size-5" /></span><ArrowUpRight aria-hidden="true" className="size-4 text-black/25 group-hover:text-[var(--forest)]" /></div>
            <h2 className="mt-5 font-display text-2xl font-semibold">{section.title}</h2><p className="mt-2 text-xs leading-5 text-[var(--muted)]">{section.description}</p><p className="mt-5 text-[10px] font-bold text-[var(--forest)]">Open secure admin panel</p>
          </a>
        ); })}
      </div>
      <p className="mt-5 text-center text-[10px] text-[var(--muted)]">Configuration opens in the secure Filament operations panel. Daily work stays here.</p>
    </AppShell>
  );
}
