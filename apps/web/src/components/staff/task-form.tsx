"use client";

import { useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { CheckCircle2, LoaderCircle } from "lucide-react";
import type { PropertyDto } from "@/data/staff-api";
import type { ReservationDto } from "@/data/api-client";
import { staffMutation, StaffMutationError } from "@/data/staff-client";

export function TaskForm({ properties, reservations, initialReservationId, demo = false }: { properties: PropertyDto[]; reservations: ReservationDto[]; initialReservationId?: string; demo?: boolean }) {
  const router = useRouter();
  const initialReservation = reservations.find((reservation) => reservation.id === initialReservationId);
  const [propertyId, setPropertyId] = useState(initialReservation?.property_id ?? properties[0]?.id ?? "");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setError(null); setFieldErrors({});
    if (demo) { setError("Demo mode is read-only. Sign in to create operational work."); return; }
    setPending(true);
    const data = new FormData(event.currentTarget);
    try {
      await staffMutation("tasks", { method: "POST", body: JSON.stringify({
        property_id: propertyId,
        reservation_id: data.get("reservation_id") || null,
        title: data.get("title"),
        description: data.get("description") || null,
        priority: data.get("priority"),
        due_at: data.get("due_at") ? new Date(String(data.get("due_at"))).toISOString() : null,
      }) });
      router.push(initialReservationId ? `/reservations/${initialReservationId}` : "/operations"); router.refresh();
    } catch (reason) {
      if (reason instanceof StaffMutationError) { setError(reason.message); setFieldErrors(reason.errors); }
      else setError("The task could not be created.");
      setPending(false);
    }
  }

  return <form onSubmit={submit} className="surface-card rounded-2xl p-5 sm:p-7">
    <div className="grid gap-5 sm:grid-cols-2">
      <Field label="Property" error={fieldErrors.property_id?.[0]}><select required value={propertyId} onChange={(event) => setPropertyId(event.target.value)} className="field-input"><option value="">Choose property</option>{properties.map((property) => <option key={property.id} value={property.id}>{property.name}</option>)}</select></Field>
      <Field label="Reservation" hint="Optional"><select name="reservation_id" defaultValue={initialReservationId ?? ""} className="field-input"><option value="">General property task</option>{reservations.filter((reservation) => reservation.property_id === propertyId).map((reservation) => <option key={reservation.id} value={reservation.id}>{reservation.confirmation_number} · {reservation.primary_guest?.first_name ?? "Guest"}</option>)}</select></Field>
      <Field label="Task title" error={fieldErrors.title?.[0]}><input required name="title" maxLength={200} placeholder="Prepare late-arrival welcome" className="field-input" /></Field>
      <Field label="Priority"><select name="priority" defaultValue="normal" className="field-input"><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></Field>
      <Field label="Due date"><input name="due_at" type="datetime-local" className="field-input" /></Field>
      <Field label="Description" hint="Optional"><textarea name="description" rows={4} maxLength={10000} className="field-input h-auto py-2" /></Field>
    </div>
    {error ? <p role="alert" className="mt-5 rounded-xl bg-[var(--red-soft)] px-4 py-3 text-xs font-semibold text-[var(--red)]">{error}</p> : null}
    <div className="mt-6 flex justify-end"><button disabled={pending || !properties.length} className="inline-flex h-11 items-center gap-2 rounded-xl bg-[var(--forest)] px-5 text-sm font-bold text-white disabled:opacity-50">{pending ? <LoaderCircle className="size-4 animate-spin" /> : <CheckCircle2 className="size-4" />}{pending ? "Creating…" : "Create task"}</button></div>
  </form>;
}

function Field({ label, hint, error, children }: { label: string; hint?: string; error?: string; children: React.ReactNode }) {
  return <label className="text-xs font-bold"><span className="flex justify-between gap-2"><span>{label}</span>{hint ? <span className="font-normal text-[var(--muted)]">{hint}</span> : null}</span><span className="mt-2 block">{children}</span>{error ? <span className="mt-1 block text-[10px] text-[var(--red)]">{error}</span> : null}</label>;
}
