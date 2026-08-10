"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  CalendarDays,
  ChartNoAxesCombined,
  ContactRound,
  LayoutDashboard,
  Settings2,
  SquareCheckBig,
  TentTree,
} from "lucide-react";
import { cn } from "@/lib/utils";

const navigation = [
  { href: "/", label: "Overview", icon: LayoutDashboard, roles: ["owner", "manager", "sales", "operations", "viewer"] },
  { href: "/calendar", label: "Master calendar", icon: CalendarDays, roles: ["owner", "manager", "sales", "operations", "guide", "viewer"] },
  { href: "/reservations", label: "Reservations", icon: TentTree, roles: ["owner", "manager", "sales", "operations"] },
  { href: "/guests", label: "Guests & CRM", icon: ContactRound, roles: ["owner", "manager", "sales", "operations"] },
  { href: "/operations", label: "Operations", icon: SquareCheckBig, roles: ["owner", "manager", "operations", "guide", "kitchen", "housekeeping"] },
  { href: "/finance", label: "Finance", icon: ChartNoAxesCombined, roles: ["owner", "manager", "finance"] },
  { href: "/settings", label: "Manage", icon: Settings2, roles: ["owner", "manager"] },
];

function isActive(pathname: string, href: string) {
  return href === "/" ? pathname === "/" : pathname.startsWith(href);
}

export function SidebarNav({ role }: { role: string }) {
  const pathname = usePathname();
  const visibleNavigation = navigation.filter((item) => item.roles.includes(role));

  return (
    <nav aria-label="Primary navigation" className="mt-8 flex flex-1 flex-col gap-1 px-3">
      {visibleNavigation.map((item) => {
        const Icon = item.icon;
        const active = isActive(pathname, item.href);

        return (
          <Link
            key={item.href}
            href={item.href}
            prefetch
            aria-current={active ? "page" : undefined}
            className={cn(
              "group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors",
              active
                ? "bg-white text-[var(--forest)] shadow-sm"
                : "text-white/65 hover:bg-white/8 hover:text-white",
            )}
          >
            <Icon
              aria-hidden="true"
              className={cn(
                "size-[18px]",
                active ? "text-[var(--amber)]" : "text-white/45 group-hover:text-white/75",
              )}
              strokeWidth={1.8}
            />
            {item.label}
          </Link>
        );
      })}
    </nav>
  );
}

export function MobileNav({ role }: { role: string }) {
  const pathname = usePathname();
  const visibleNavigation = navigation.filter((item) => item.roles.includes(role));

  return (
    <nav
      aria-label="Mobile navigation"
      className="scrollbar-slim flex gap-1 overflow-x-auto border-b border-black/7 bg-[var(--surface)] px-3 py-2 lg:hidden"
    >
      {visibleNavigation.map((item) => {
        const Icon = item.icon;
        const active = isActive(pathname, item.href);
        return (
          <Link
            key={item.href}
            href={item.href}
            aria-current={active ? "page" : undefined}
            className={cn(
              "flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-xs font-semibold",
              active ? "bg-[var(--forest)] text-white" : "text-[var(--muted)]",
            )}
          >
            <Icon aria-hidden="true" className="size-4" />
            {item.label}
          </Link>
        );
      })}
    </nav>
  );
}
