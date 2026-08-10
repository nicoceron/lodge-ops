import { ArrowDownRight, ArrowUpRight } from "lucide-react";
import { cn } from "@/lib/utils";

const toneClasses = {
  forest: "bg-[var(--forest-soft)] text-[var(--forest)]",
  amber: "bg-[var(--amber-soft)] text-[var(--amber)]",
  red: "bg-[var(--red-soft)] text-[var(--red)]",
  blue: "bg-[var(--blue-soft)] text-[var(--blue)]",
};

export function MetricCard({ label, value, detail, tone }: { label: string; value: string; detail: string; tone: keyof typeof toneClasses }) {
  const positive = detail.includes("+") || detail.includes("collected");
  const Icon = positive ? ArrowUpRight : ArrowDownRight;
  return (
    <article className="surface-card rounded-2xl p-4 sm:p-5">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-xs font-semibold text-[var(--muted)]">{label}</p>
          <p className="mt-2 font-display text-[34px] leading-none font-semibold tracking-[-0.03em]">{value}</p>
        </div>
        <span className={cn("grid size-9 place-items-center rounded-xl", toneClasses[tone])}>
          <Icon aria-hidden="true" className="size-4" />
        </span>
      </div>
      <p className="mt-4 text-[11px] font-medium text-[var(--muted)]">{detail}</p>
    </article>
  );
}
