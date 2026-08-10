import type { ReactNode } from "react";
import Link from "next/link";
import { cookies } from "next/headers";
import { Bell, Command, Plus, Search } from "lucide-react";
import { MobileNav, SidebarNav } from "@/components/sidebar-nav";
import { LogoutButton } from "@/components/staff/logout-button";
import { TenantSwitcher } from "@/components/staff/tenant-switcher";
import { requireStaffUser, selectedTenant } from "@/data/staff-api";
import { tenant } from "@/lib/demo-data";

type AppShellProps = {
  children: ReactNode;
  eyebrow: string;
  title: string;
  description: string;
  action?: {
    label: string;
    shortLabel?: string;
    href: string;
  };
};

export async function AppShell({ children, eyebrow, title, description, action }: AppShellProps) {
  const isDemo = process.env.NEXT_PUBLIC_DEMO_MODE === "true";
  const user = await requireStaffUser();
  const selectedId = (await cookies()).get("lodgeops_tenant_id")?.value;
  const activeTenant = selectedTenant(user, selectedId);
  const role = user.membership?.role ?? activeTenant?.role ?? "staff";
  const workspace = isDemo
    ? tenant
    : {
        initials: (activeTenant?.name ?? "Lodge").split(/\s+/).slice(0, 2).map((part) => part[0]).join("").toUpperCase(),
        shortName: activeTenant?.name ?? "Active lodge",
        location: activeTenant?.property?.name ?? "Tenant workspace",
      };
  const userInitials = user.name.split(/\s+/).slice(0, 2).map((part) => part[0]).join("").toUpperCase();

  return (
    <div className="min-h-screen lg:grid lg:grid-cols-[248px_minmax(0,1fr)]">
      <aside className="hidden min-h-screen flex-col bg-[var(--forest)] lg:sticky lg:top-0 lg:flex lg:h-screen">
        <div className="px-6 pt-6">
          <div className="flex items-center gap-3">
            <div className="grid size-10 place-items-center rounded-xl border border-white/15 bg-white/10 font-display text-xl text-white">
              L
            </div>
            <div>
              <div className="font-display text-[22px] leading-5 text-white">LodgeOps</div>
              <div className="mt-1 text-[10px] font-semibold tracking-[0.16em] text-white/45 uppercase">
                Run with clarity
              </div>
            </div>
          </div>
        </div>
        <SidebarNav />
        <div className="m-3 rounded-2xl border border-white/10 bg-black/10 p-3">
          <div className="flex w-full items-center gap-3 text-left">
            <span className="grid size-9 place-items-center rounded-lg bg-[#dbe6da] text-xs font-bold text-[var(--forest)]">
              {workspace.initials}
            </span>
            <span className="min-w-0 flex-1">
              <span className="block truncate text-xs font-semibold text-white">{workspace.shortName}</span>
              <span className="mt-0.5 block truncate text-[11px] text-white/45">{workspace.location}</span>
            </span>
          </div>
          {user.tenants.length > 1 && activeTenant ? <div className="mt-3"><TenantSwitcher tenants={user.tenants} selectedId={activeTenant.id} /></div> : null}
          <div className="mt-2 border-t border-white/8 pt-2"><LogoutButton /></div>
        </div>
      </aside>

      <div className="min-w-0">
        <header className="border-b border-black/7 bg-[color-mix(in_srgb,var(--surface)_92%,transparent)] backdrop-blur-xl lg:sticky lg:top-0 lg:z-30">
          <div className="flex h-16 items-center gap-3 px-4 sm:px-6 lg:px-8">
            <div className="flex items-center gap-2 lg:hidden">
              <div className="grid size-9 place-items-center rounded-xl bg-[var(--forest)] font-display text-lg text-white">L</div>
              <span className="font-display text-xl">LodgeOps</span>
            </div>
            <form action="/search" role="search" className="ml-auto hidden w-full max-w-sm items-center gap-2 rounded-xl border border-black/8 bg-white/70 px-3 py-2 text-sm text-[var(--muted)] shadow-sm sm:flex lg:ml-0">
              <Search aria-hidden="true" className="size-4" />
              <label className="min-w-0 flex-1"><span className="sr-only">Search guests, reservations, and resources</span><input name="q" placeholder="Search guests, reservations, resources…" className="w-full bg-transparent text-sm outline-none placeholder:text-[var(--muted)]" /></label>
              <span className="flex items-center gap-1 rounded-md border border-black/8 bg-white px-1.5 py-0.5 text-[10px] font-semibold text-black/45">
                <Command aria-hidden="true" className="size-3" /> K
              </span>
            </form>
            <Link
              href="/operations"
              aria-label={isDemo ? "Notifications, 3 unread" : "Notifications"}
              className="relative grid size-10 place-items-center rounded-xl border border-black/8 bg-white/70 text-[var(--muted)] shadow-sm"
            >
              <Bell aria-hidden="true" className="size-[18px]" />
              {isDemo ? <span className="absolute right-2 top-2 size-2 rounded-full border-2 border-white bg-[var(--red)]" /> : null}
            </Link>
            {user.tenants.length > 1 && activeTenant ? <div className="hidden md:block lg:hidden"><TenantSwitcher tenants={user.tenants} selectedId={activeTenant.id} variant="header" /></div> : null}
            <div className="flex items-center gap-2 rounded-xl py-1 pl-1 pr-2">
              <span className="grid size-8 place-items-center rounded-lg bg-[var(--amber-soft)] text-xs font-bold text-[var(--amber)]">{userInitials || "ST"}</span>
              <span className="hidden text-left sm:block">
                <span className="block text-xs font-semibold">{user.name}</span>
                <span className="block text-[10px] capitalize text-[var(--muted)]">{role.replaceAll("_", " ")}</span>
              </span>
            </div>
            <div className="lg:hidden"><LogoutButton compact /></div>
          </div>
          <MobileNav />
        </header>

        <main className="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
          <div className="mx-auto max-w-[1480px]">
            <div className="mb-7 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
              <div>
                <p className="mb-2 text-[11px] font-bold tracking-[0.15em] text-[var(--amber)] uppercase">{eyebrow}</p>
                <h1 className="font-display text-4xl leading-none font-medium tracking-[-0.025em] text-[var(--foreground)] sm:text-[44px]">
                  {title}
                </h1>
                <p className="mt-2 max-w-2xl text-sm leading-6 text-[var(--muted)]">{description}</p>
              </div>
              {action ? (
                <Link
                  href={action.href}
                  className="inline-flex h-11 items-center justify-center gap-2 self-start rounded-xl bg-[var(--forest)] px-4 text-sm font-semibold text-white shadow-[0_8px_22px_rgb(23_61_46/18%)] transition-transform hover:-translate-y-0.5 sm:self-auto"
                >
                  <Plus aria-hidden="true" className="size-4" />
                  <span className="sm:hidden">{action.shortLabel ?? action.label}</span>
                  <span className="hidden sm:inline">{action.label}</span>
                </Link>
              ) : null}
            </div>
            {children}
          </div>
        </main>
      </div>
    </div>
  );
}
