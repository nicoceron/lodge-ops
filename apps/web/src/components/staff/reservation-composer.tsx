"use client";

import { useMemo, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { CalendarPlus, LoaderCircle, Plus, Trash2 } from "lucide-react";
import { staffMutation, StaffMutationError } from "@/data/staff-client";
import type { GuestDto, ProgramDto, PropertyDto, ReservationDetailDto, ResourceDto } from "@/data/staff-api";

type AllocationDraft = { key: string; resource_id: string; quantity: number };

function localDateTime(daysAhead: number, hour: number) {
  const date = new Date();
  date.setDate(date.getDate() + daysAhead);
  date.setHours(hour, 0, 0, 0);
  const offset = date.getTimezoneOffset() * 60_000;
  return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

export function ReservationComposer({
  properties,
  programs,
  guests,
  resources,
  initialHold = false,
  demo = false,
}: {
  properties: PropertyDto[];
  programs: ProgramDto[];
  guests: GuestDto[];
  resources: ResourceDto[];
  initialHold?: boolean;
  demo?: boolean;
}) {
  const router = useRouter();
  const [propertyId, setPropertyId] = useState(properties[0]?.id ?? "");
  const [programId, setProgramId] = useState("");
  const [primaryGuestId, setPrimaryGuestId] = useState("");
  const [companionIds, setCompanionIds] = useState<string[]>([]);
  const [startsAt, setStartsAt] = useState(localDateTime(1, 15));
  const [endsAt, setEndsAt] = useState(localDateTime(3, 11));
  const [adults, setAdults] = useState(2);
  const [children, setChildren] = useState(0);
  const [currency, setCurrency] = useState("USD");
  const [subtotal, setSubtotal] = useState("0");
  const [tax, setTax] = useState("0");
  const [source, setSource] = useState("direct");
  const [notes, setNotes] = useState("");
  const [hold, setHold] = useState(initialHold);
  const [allocations, setAllocations] = useState<AllocationDraft[]>([]);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  const filteredPrograms = useMemo(() => programs.filter((program) => program.property_id === propertyId), [programs, propertyId]);
  const filteredResources = useMemo(() => resources.filter((resource) => resource.property_id === propertyId && resource.is_active), [resources, propertyId]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    setFieldErrors({});
    if (demo) {
      setError("Demo mode is read-only. Sign in to a live tenant to create this reservation.");
      return;
    }
    if (!propertyId || !primaryGuestId || !startsAt || !endsAt) {
      setError("Choose a property, primary guest, arrival, and departure.");
      return;
    }
    if (new Date(endsAt) <= new Date(startsAt)) {
      setError("Departure must be after arrival.");
      return;
    }

    setPending(true);
    try {
      const response = await staffMutation<{ data: ReservationDetailDto }>("reservations", {
        method: "POST",
        body: JSON.stringify({
          property_id: propertyId,
          program_id: programId || null,
          primary_guest_id: primaryGuestId,
          guest_ids: companionIds,
          source: source || null,
          starts_at: new Date(startsAt).toISOString(),
          ends_at: new Date(endsAt).toISOString(),
          adults,
          children,
          currency: currency.toUpperCase(),
          subtotal_minor: Math.round(Number(subtotal || 0) * 100),
          tax_minor: Math.round(Number(tax || 0) * 100),
          notes: notes || null,
          allocations: allocations.filter((item) => item.resource_id).map((item) => ({
            resource_id: item.resource_id,
            starts_at: new Date(startsAt).toISOString(),
            ends_at: new Date(endsAt).toISOString(),
            quantity: item.quantity,
          })),
        }),
      });
      if (hold) {
        await staffMutation(`reservations/${response.data.id}/transition`, {
          method: "POST",
          body: JSON.stringify({ status: "hold", hold_minutes: 120 }),
        });
      }
      router.push(`/reservations/${response.data.id}`);
      router.refresh();
    } catch (reason) {
      if (reason instanceof StaffMutationError) {
        setError(reason.message);
        setFieldErrors(reason.errors);
      } else setError("The reservation could not be created. Try again.");
      setPending(false);
    }
  }

  function addAllocation() {
    setAllocations((items) => [...items, { key: crypto.randomUUID(), resource_id: "", quantity: 1 }]);
  }

  return (
    <form onSubmit={submit} className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
      <div className="space-y-5">
        <section className="surface-card rounded-2xl p-5" aria-labelledby="stay-heading">
          <h2 id="stay-heading" className="text-sm font-bold">Stay and guest</h2>
          <p className="mt-1 text-xs text-[var(--muted)]">Select the commercial package and everyone travelling on this reservation.</p>
          <div className="mt-5 grid gap-4 sm:grid-cols-2">
            <Field label="Property" error={fieldErrors.property_id?.[0]}>
              <select required value={propertyId} onChange={(event) => { setPropertyId(event.target.value); setProgramId(""); setAllocations([]); }} className="field-input">
                <option value="">Choose a property</option>
                {properties.map((property) => <option key={property.id} value={property.id}>{property.name}</option>)}
              </select>
            </Field>
            <Field label="Program" error={fieldErrors.program_id?.[0]}>
              <select value={programId} onChange={(event) => setProgramId(event.target.value)} className="field-input">
                <option value="">Custom lodge stay</option>
                {filteredPrograms.map((program) => <option key={program.id} value={program.id}>{program.name}</option>)}
              </select>
            </Field>
            <Field label="Primary guest" error={fieldErrors.primary_guest_id?.[0]}>
              <select required value={primaryGuestId} onChange={(event) => { setPrimaryGuestId(event.target.value); setCompanionIds((ids) => ids.filter((id) => id !== event.target.value)); }} className="field-input">
                <option value="">Choose a guest</option>
                {guests.map((guest) => <option key={guest.id} value={guest.id}>{guest.full_name} {guest.email ? `— ${guest.email}` : ""}</option>)}
              </select>
            </Field>
            <Field label="Companions" hint="Use Cmd/Ctrl to choose several">
              <select multiple value={companionIds} onChange={(event) => setCompanionIds(Array.from(event.target.selectedOptions, (option) => option.value))} className="field-input min-h-24">
                {guests.filter((guest) => guest.id !== primaryGuestId).map((guest) => <option key={guest.id} value={guest.id}>{guest.full_name}</option>)}
              </select>
            </Field>
            <Field label="Arrival" error={fieldErrors.starts_at?.[0]}><input required type="datetime-local" value={startsAt} onChange={(event) => setStartsAt(event.target.value)} className="field-input" /></Field>
            <Field label="Departure" error={fieldErrors.ends_at?.[0]}><input required type="datetime-local" value={endsAt} onChange={(event) => setEndsAt(event.target.value)} className="field-input" /></Field>
            <Field label="Adults"><input required min={1} max={1000} type="number" value={adults} onChange={(event) => setAdults(Number(event.target.value))} className="field-input" /></Field>
            <Field label="Children"><input required min={0} max={1000} type="number" value={children} onChange={(event) => setChildren(Number(event.target.value))} className="field-input" /></Field>
          </div>
        </section>

        <section className="surface-card rounded-2xl p-5" aria-labelledby="allocation-heading">
          <div className="flex items-start justify-between gap-4">
            <div><h2 id="allocation-heading" className="text-sm font-bold">Resource allocations</h2><p className="mt-1 text-xs text-[var(--muted)]">Reserve rooms, guides, vehicles, or equipment for the full stay.</p></div>
            <button type="button" onClick={addAllocation} className="inline-flex h-9 items-center gap-2 rounded-lg border border-black/10 bg-white px-3 text-xs font-bold"><Plus className="size-3.5" />Add</button>
          </div>
          <div className="mt-4 space-y-3">
            {allocations.map((allocation, index) => (
              <div key={allocation.key} className="grid gap-3 rounded-xl bg-[#faf8f2] p-3 sm:grid-cols-[1fr_110px_40px]">
                <label className="text-[10px] font-bold text-[var(--muted)]">Resource
                  <select required value={allocation.resource_id} onChange={(event) => setAllocations((items) => items.map((item) => item.key === allocation.key ? { ...item, resource_id: event.target.value } : item))} className="field-input mt-1.5">
                    <option value="">Choose resource</option>
                    {filteredResources.map((resource) => <option key={resource.id} value={resource.id}>{resource.name} · {resource.type}</option>)}
                  </select>
                </label>
                <label className="text-[10px] font-bold text-[var(--muted)]">Quantity
                  <input min={1} type="number" value={allocation.quantity} onChange={(event) => setAllocations((items) => items.map((item) => item.key === allocation.key ? { ...item, quantity: Number(event.target.value) } : item))} className="field-input mt-1.5" />
                </label>
                <button type="button" aria-label={`Remove allocation ${index + 1}`} onClick={() => setAllocations((items) => items.filter((item) => item.key !== allocation.key))} className="mt-5 grid size-10 place-items-center rounded-lg text-[var(--red)] hover:bg-[var(--red-soft)]"><Trash2 className="size-4" /></button>
              </div>
            ))}
            {!allocations.length ? <p className="rounded-xl border border-dashed border-black/10 px-4 py-6 text-center text-xs text-[var(--muted)]">No resources selected. You can allocate them now or from the reservation workspace.</p> : null}
          </div>
        </section>
      </div>

      <aside className="surface-card h-fit rounded-2xl p-5 xl:sticky xl:top-5">
        <div className="flex items-center gap-3"><span className="grid size-10 place-items-center rounded-xl bg-[var(--forest-soft)] text-[var(--forest)]"><CalendarPlus className="size-5" /></span><div><h2 className="text-sm font-bold">Commercial summary</h2><p className="text-[10px] text-[var(--muted)]">Amounts are stored in minor units</p></div></div>
        <div className="mt-5 space-y-4">
          <Field label="Source"><select value={source} onChange={(event) => setSource(event.target.value)} className="field-input"><option value="direct">Direct</option><option value="agency">Agency</option><option value="website">Website</option><option value="returning_guest">Returning guest</option><option value="other">Other</option></select></Field>
          <div className="grid grid-cols-[90px_1fr] gap-3"><Field label="Currency"><input required maxLength={3} value={currency} onChange={(event) => setCurrency(event.target.value.toUpperCase())} className="field-input uppercase" /></Field><Field label="Subtotal"><input min="0" step="0.01" type="number" value={subtotal} onChange={(event) => setSubtotal(event.target.value)} className="field-input" /></Field></div>
          <Field label="Tax"><input min="0" step="0.01" type="number" value={tax} onChange={(event) => setTax(event.target.value)} className="field-input" /></Field>
          <Field label="Internal notes"><textarea rows={4} value={notes} onChange={(event) => setNotes(event.target.value)} className="field-input h-auto py-2" /></Field>
          <label className="flex cursor-pointer items-start gap-3 rounded-xl bg-[var(--amber-soft)]/60 p-3"><input type="checkbox" checked={hold} onChange={(event) => setHold(event.target.checked)} className="mt-0.5 size-4" /><span><span className="block text-xs font-bold">Place on a 2-hour hold</span><span className="mt-1 block text-[10px] text-[var(--muted)]">Create the draft, then reserve inventory while the guest decides.</span></span></label>
        </div>
        {error ? <div role="alert" className="mt-4 rounded-xl bg-[var(--red-soft)] p-3 text-xs font-semibold text-[var(--red)]">{error}</div> : null}
        <button disabled={pending || !properties.length || !guests.length} className="mt-5 inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-[var(--forest)] px-4 text-xs font-bold text-white disabled:opacity-50">{pending ? <LoaderCircle className="size-4 animate-spin" /> : <CalendarPlus className="size-4" />}{pending ? "Creating…" : hold ? "Create and hold" : "Create reservation"}</button>
      </aside>
    </form>
  );
}

function Field({ label, hint, error, children }: { label: string; hint?: string; error?: string; children: React.ReactNode }) {
  return <label className="block text-[10px] font-bold text-[var(--muted)]"><span className="flex justify-between gap-2"><span>{label}</span>{hint ? <span className="font-normal">{hint}</span> : null}</span><span className="mt-1.5 block">{children}</span>{error ? <span className="mt-1 block text-[var(--red)]">{error}</span> : null}</label>;
}
