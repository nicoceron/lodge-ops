"use client";

import { useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { CheckCircle2, LoaderCircle, Trash2 } from "lucide-react";
import type { ProgramDto, ResourceBlockDto, ResourceDto, ServiceOccurrenceDto } from "@/data/staff-api";
import { staffMutation, StaffMutationError } from "@/data/staff-client";

function localDateTime(value: string) {
  const date = new Date(value);
  const offset = date.getTimezoneOffset() * 60_000;
  return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

export function CalendarEventEditor({ occurrence, block, programs, resources }: { occurrence?: ServiceOccurrenceDto; block?: ResourceBlockDto; programs: ProgramDto[]; resources: ResourceDto[] }) {
  const router = useRouter();
  const event = occurrence ?? block;
  const [pending, setPending] = useState<"save" | "delete" | null>(null);
  const [error, setError] = useState<string | null>(null);
  if (!event) return null;

  const isOccurrence = Boolean(occurrence);
  const endpoint = isOccurrence ? `service-occurrences/${event.id}` : `resource-blocks/${event.id}`;

  async function submit(submission: FormEvent<HTMLFormElement>) {
    submission.preventDefault(); setPending("save"); setError(null);
    const data = new FormData(submission.currentTarget);
    const payload = isOccurrence ? {
      program_id: data.get("program_id"), starts_at: new Date(String(data.get("starts_at"))).toISOString(), ends_at: new Date(String(data.get("ends_at"))).toISOString(), capacity: Number(data.get("capacity")), meeting_point: data.get("meeting_point") || null, is_cancelled: data.get("is_cancelled") === "on",
    } : {
      resource_id: data.get("resource_id"), starts_at: new Date(String(data.get("starts_at"))).toISOString(), ends_at: new Date(String(data.get("ends_at"))).toISOString(), reason: data.get("reason"), notes: data.get("notes") || null,
    };
    try {
      await staffMutation(endpoint, { method: "PUT", body: JSON.stringify(payload) }); router.refresh();
    } catch (reason) {
      setError(reason instanceof StaffMutationError ? reason.message : "This calendar event could not be saved.");
    } finally { setPending(null); }
  }

  async function remove() {
    setPending("delete"); setError(null);
    try {
      await staffMutation(endpoint, { method: "DELETE" }); router.push("/calendar"); router.refresh();
    } catch (reason) {
      setError(reason instanceof StaffMutationError ? reason.message : "This calendar event could not be removed."); setPending(null);
    }
  }

  return <form onSubmit={submit} className="surface-card rounded-2xl p-5 sm:p-7">
    <div className="grid gap-5 sm:grid-cols-2">
      {occurrence ? <>
        <Field label="Program"><select name="program_id" defaultValue={occurrence.program_id} className="field-input">{programs.filter((program) => program.property_id === occurrence.property_id).map((program) => <option key={program.id} value={program.id}>{program.name}</option>)}</select></Field>
        <Field label="Capacity"><input required name="capacity" type="number" min={1} defaultValue={occurrence.capacity} className="field-input" /></Field>
        <Field label="Meeting point"><input name="meeting_point" defaultValue={occurrence.meeting_point ?? ""} className="field-input" /></Field>
        <label className="flex items-center gap-2 self-end pb-3 text-xs font-bold"><input name="is_cancelled" type="checkbox" defaultChecked={occurrence.is_cancelled} className="size-4" />Occurrence cancelled</label>
      </> : null}
      {block ? <>
        <Field label="Resource"><select name="resource_id" defaultValue={block.resource_id} className="field-input">{resources.map((resource) => <option key={resource.id} value={resource.id}>{resource.name} · {resource.code}</option>)}</select></Field>
        <Field label="Reason"><input required name="reason" maxLength={200} defaultValue={block.reason} className="field-input" /></Field>
      </> : null}
      <Field label="Starts"><input required name="starts_at" type="datetime-local" defaultValue={localDateTime(event.starts_at)} className="field-input" /></Field>
      <Field label="Ends"><input required name="ends_at" type="datetime-local" defaultValue={localDateTime(event.ends_at)} className="field-input" /></Field>
      {block ? <Field label="Notes"><textarea name="notes" rows={4} defaultValue={block.notes ?? ""} className="field-input h-auto py-2" /></Field> : null}
    </div>
    {error ? <p role="alert" className="mt-5 rounded-xl bg-[var(--red-soft)] px-4 py-3 text-xs font-semibold text-[var(--red)]">{error}</p> : null}
    <div className="mt-6 flex flex-wrap justify-between gap-3">
      <button type="button" onClick={remove} disabled={pending !== null} className="inline-flex h-11 items-center gap-2 rounded-xl border border-[var(--red)]/20 bg-[var(--red-soft)] px-4 text-xs font-bold text-[var(--red)] disabled:opacity-50">{pending === "delete" ? <LoaderCircle className="size-4 animate-spin" /> : <Trash2 className="size-4" />}{isOccurrence ? "Cancel occurrence" : "Remove block"}</button>
      <button disabled={pending !== null} className="inline-flex h-11 items-center gap-2 rounded-xl bg-[var(--forest)] px-5 text-xs font-bold text-white disabled:opacity-50">{pending === "save" ? <LoaderCircle className="size-4 animate-spin" /> : <CheckCircle2 className="size-4" />}Save event</button>
    </div>
  </form>;
}

function Field({ label, children }: { label: string; children: React.ReactNode }) { return <label className="text-xs font-bold">{label}<span className="mt-2 block">{children}</span></label>; }
