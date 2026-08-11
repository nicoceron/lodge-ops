"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { CircleCheck, LoaderCircle } from "lucide-react";
import { staffMutation } from "@/data/staff-client";
import { cn } from "@/lib/utils";

export function TaskStatusButton({ id, title, done, demo = false }: { id: string; title: string; done: boolean; demo?: boolean }) {
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [error, setError] = useState(false);

  async function toggle() {
    if (demo) return;
    setPending(true); setError(false);
    try {
      await staffMutation(`tasks/${id}`, { method: "PUT", body: JSON.stringify({ status: done ? "todo" : "done" }) });
      router.refresh();
    } catch {
      setError(true); setPending(false);
    }
  }

  return <button type="button" onClick={toggle} disabled={pending || demo} title={demo ? "Read-only demo task" : error ? "Could not update task. Try again." : undefined} aria-label={`${done ? "Reopen" : "Complete"} ${title}`} className={cn("grid size-8 place-items-center rounded-full border disabled:cursor-not-allowed", done ? "border-[var(--forest)] bg-[var(--forest)] text-white" : error ? "border-[var(--red)] bg-[var(--red-soft)] text-[var(--red)]" : "border-black/12 bg-white text-black/20")}>
    {pending ? <LoaderCircle aria-hidden="true" className="size-4 animate-spin" /> : <CircleCheck aria-hidden="true" className="size-4" />}
  </button>;
}
