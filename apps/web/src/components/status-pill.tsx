import { AlertTriangle, Check, Clock3, CircleDot } from "lucide-react";
import { cn } from "@/lib/utils";

type Tone = "ready" | "attention" | "blocked" | "confirmed" | "tentative" | "in_house" | "completed" | "neutral";

const config: Record<Tone, { label: string; classes: string; icon: typeof Check }> = {
  ready: { label: "Ready", classes: "bg-[var(--forest-soft)] text-[var(--forest)]", icon: Check },
  attention: { label: "Needs attention", classes: "bg-[var(--amber-soft)] text-[#8a501f]", icon: Clock3 },
  blocked: { label: "Blocked", classes: "bg-[var(--red-soft)] text-[var(--red)]", icon: AlertTriangle },
  confirmed: { label: "Confirmed", classes: "bg-[var(--blue-soft)] text-[var(--blue)]", icon: Check },
  tentative: { label: "Tentative", classes: "bg-[var(--amber-soft)] text-[#8a501f]", icon: Clock3 },
  in_house: { label: "In house", classes: "bg-[var(--forest-soft)] text-[var(--forest)]", icon: CircleDot },
  completed: { label: "Completed", classes: "bg-black/5 text-[var(--muted)]", icon: Check },
  neutral: { label: "Status", classes: "bg-black/5 text-[var(--muted)]", icon: CircleDot },
};

export function StatusPill({ tone, label, compact = false }: { tone: Tone; label?: string; compact?: boolean }) {
  const item = config[tone];
  const Icon = item.icon;
  return (
    <span
      className={cn(
        "inline-flex w-fit items-center rounded-full font-semibold whitespace-nowrap",
        item.classes,
        compact ? "gap-1 px-2 py-1 text-[10px]" : "gap-1.5 px-2.5 py-1.5 text-[11px]",
      )}
    >
      <Icon aria-hidden="true" className={compact ? "size-3" : "size-3.5"} strokeWidth={2.2} />
      {label ?? item.label}
    </span>
  );
}
