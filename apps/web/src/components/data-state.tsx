import { AlertTriangle, Database } from "lucide-react";

export function DataState({
  kind,
  title,
  description,
}: {
  kind: "empty" | "error";
  title: string;
  description: string;
}) {
  const Icon = kind === "error" ? AlertTriangle : Database;
  return (
    <section className="surface-card grid min-h-64 place-items-center rounded-2xl p-8 text-center" role={kind === "error" ? "alert" : "status"}>
      <div>
        <span className={`mx-auto grid size-12 place-items-center rounded-2xl ${kind === "error" ? "bg-[var(--red-soft)] text-[var(--red)]" : "bg-[var(--forest-soft)] text-[var(--forest)]"}`}>
          <Icon aria-hidden="true" className="size-5" />
        </span>
        <h2 className="mt-4 font-display text-2xl font-semibold">{title}</h2>
        <p className="mx-auto mt-2 max-w-lg text-xs leading-5 text-[var(--muted)]">{description}</p>
      </div>
    </section>
  );
}

export function DataNotice({ children }: { children: string }) {
  return (
    <div className="mb-5 flex items-center gap-2 rounded-xl border border-[var(--amber)]/20 bg-[var(--amber-soft)]/55 px-4 py-3 text-xs text-[#84552d]" role="status">
      <AlertTriangle aria-hidden="true" className="size-4 shrink-0" />
      {children}
    </div>
  );
}
