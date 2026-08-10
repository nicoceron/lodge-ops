"use client";

import { useState, type FormEvent } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { ArrowRight, BedDouble, Check, CircleDollarSign, ClipboardCheck, LoaderCircle, Plus, RotateCcw, Trash2, UsersRound } from "lucide-react";
import type { DepositDto, FolioLineDto, PaymentDto, ReservationDetailDto, ResourceDto, TaskDto } from "@/data/staff-api";
import { staffMutation, StaffMutationError } from "@/data/staff-client";
import { formatMoney } from "@/lib/utils";

const statusLabels: Record<ReservationDetailDto["status"], string> = {
  draft: "Draft", hold: "On hold", confirmed: "Confirmed", checked_in: "In house", checked_out: "Checked out", cancelled: "Cancelled", no_show: "No show",
};

function displayDate(value: string | null) {
  if (!value) return "Not set";
  return new Intl.DateTimeFormat("en-US", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
}

function minorAmount(value: FormDataEntryValue | null) {
  return Math.round(Number(value || 0) * 100);
}

export function ReservationWorkspace({
  reservation,
  resources,
  tasks,
  deposits,
  payments,
  folio,
  canManageGuestMoney = true,
  canManageOperations = true,
  demo = false,
}: {
  reservation: ReservationDetailDto;
  resources: ResourceDto[];
  tasks: TaskDto[];
  deposits: DepositDto[];
  payments: PaymentDto[];
  folio: FolioLineDto[];
  canManageGuestMoney?: boolean;
  canManageOperations?: boolean;
  demo?: boolean;
}) {
  const router = useRouter();
  const [pending, setPending] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function mutate(key: string, path: string, init: RequestInit) {
    setError(null);
    if (demo) {
      setError("Demo mode is read-only. Sign in to a live tenant to save operational changes.");
      return;
    }
    setPending(key);
    try {
      await staffMutation(path, init);
      router.refresh();
    } catch (reason) {
      setError(reason instanceof StaffMutationError ? reason.message : "The change could not be saved. Try again.");
    } finally {
      setPending(null);
    }
  }

  async function formMutation(event: FormEvent<HTMLFormElement>, key: string, path: string, payload: (data: FormData) => Record<string, unknown>) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    await mutate(key, path, { method: "POST", body: JSON.stringify(payload(data)) });
    if (!demo) form.reset();
  }

  const transitions: Array<{ status: string; label: string; emphasis?: boolean }> = [];
  if (reservation.status === "draft") transitions.push({ status: "hold", label: "Place on hold" }, { status: "confirmed", label: "Confirm", emphasis: true });
  if (reservation.status === "hold") transitions.push({ status: "draft", label: "Release hold" }, { status: "confirmed", label: "Confirm", emphasis: true });
  if (reservation.status === "confirmed") transitions.push({ status: "checked_in", label: "Check in", emphasis: true }, { status: "cancelled", label: "Cancel" }, { status: "no_show", label: "Mark no-show" });
  if (reservation.status === "checked_in") transitions.push({ status: "checked_out", label: "Check out", emphasis: true });

  const paidMinor = payments.filter((payment) => payment.status === "succeeded").reduce((total, payment) => total + payment.amount_minor, 0);
  const folioMinor = folio.reduce((total, line) => total + line.amount_minor, 0);

  return <div className="space-y-5">
    {error ? <div role="alert" className="rounded-xl border border-[var(--red)]/15 bg-[var(--red-soft)] px-4 py-3 text-xs font-semibold text-[var(--red)]">{error}</div> : null}

    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
      <Summary icon={<UsersRound className="size-4" />} label="Party" value={`${reservation.adults + reservation.children} guests`} note={`${reservation.adults} adults · ${reservation.children} children`} />
      <Summary icon={<BedDouble className="size-4" />} label="Stay" value={`${displayDate(reservation.starts_at).split(",")[0]} → ${displayDate(reservation.ends_at).split(",")[0]}`} note={reservation.program?.name ?? "Custom stay"} />
      <Summary icon={<CircleDollarSign className="size-4" />} label="Reservation total" value={formatMoney(reservation.total_minor, reservation.currency)} note={`${formatMoney(paidMinor, reservation.currency)} collected`} />
      <Summary icon={<ClipboardCheck className="size-4" />} label="Status" value={statusLabels[reservation.status]} note={reservation.hold_expires_at ? `Expires ${displayDate(reservation.hold_expires_at)}` : `Revision ${reservation.revision}`} />
    </div>

    {transitions.length ? <section className="surface-card flex flex-col gap-4 rounded-2xl p-5 sm:flex-row sm:items-center sm:justify-between">
      <div><h2 className="text-sm font-bold">Move this stay forward</h2><p className="mt-1 text-xs text-[var(--muted)]">Status changes immediately update the operational plan and inventory.</p></div>
      <div className="flex flex-wrap gap-2">{transitions.map((transition) => <button key={transition.status} type="button" disabled={pending !== null} onClick={() => mutate(`status-${transition.status}`, transition.status === "confirmed" ? `reservations/${reservation.id}/confirm` : `reservations/${reservation.id}/transition`, { method: "POST", body: JSON.stringify(transition.status === "hold" ? { status: transition.status, hold_minutes: 120 } : { status: transition.status }) })} className={transition.emphasis ? "inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--forest)] px-4 text-xs font-bold text-white disabled:opacity-50" : "inline-flex h-10 items-center gap-2 rounded-xl border border-black/10 bg-white px-4 text-xs font-bold disabled:opacity-50"}>{pending === `status-${transition.status}` ? <LoaderCircle className="size-3.5 animate-spin" /> : <ArrowRight className="size-3.5" />}{transition.label}</button>)}</div>
    </section> : null}

    <div className="grid gap-5 xl:grid-cols-2">
      <WorkspaceSection title="Guests and notes" subtitle="The complete party stays attached to the booking.">
        <div className="space-y-2">
          {reservation.guests?.map((guest) => <Link key={guest.id} href={`/guests/${guest.id}`} className="flex items-center justify-between rounded-xl bg-[#faf8f2] px-4 py-3 text-xs font-bold hover:bg-[var(--forest-soft)]"><span>{guest.full_name}{guest.id === reservation.primary_guest?.id ? <span className="ml-2 text-[9px] text-[var(--forest)]">PRIMARY</span> : null}</span><ArrowRight className="size-3.5" /></Link>)}
          {!reservation.guests?.length && reservation.primary_guest ? <Link href={`/guests/${reservation.primary_guest.id}`} className="flex items-center justify-between rounded-xl bg-[#faf8f2] px-4 py-3 text-xs font-bold">{reservation.primary_guest.first_name} {reservation.primary_guest.last_name}<ArrowRight className="size-3.5" /></Link> : null}
        </div>
        <p className="mt-4 rounded-xl border border-black/7 p-3 text-xs leading-5 text-[var(--muted)]">{reservation.notes || "No internal reservation notes."}</p>
      </WorkspaceSection>

      <WorkspaceSection title="Resource allocations" subtitle="Rooms and operational resources assigned to the stay.">
        <div className="space-y-2">{reservation.allocations?.map((allocation) => <div key={allocation.id} className="flex items-center gap-3 rounded-xl bg-[#faf8f2] p-3"><span className="grid size-9 place-items-center rounded-lg bg-white text-[var(--forest)]"><BedDouble className="size-4" /></span><div className="min-w-0 flex-1"><p className="truncate text-xs font-bold">{allocation.resource?.name ?? "Service occurrence"}</p><p className="mt-1 text-[10px] text-[var(--muted)]">{allocation.status} · quantity {allocation.quantity}</p></div>{allocation.status !== "released" ? <button type="button" disabled={pending !== null} aria-label={`Release ${allocation.resource?.name ?? "allocation"}`} onClick={() => mutate(`allocation-${allocation.id}`, `reservations/${reservation.id}/allocations/${allocation.id}`, { method: "DELETE" })} className="grid size-9 place-items-center rounded-lg text-[var(--red)] hover:bg-[var(--red-soft)]"><Trash2 className="size-4" /></button> : null}</div>)}</div>
        <form onSubmit={(event) => formMutation(event, "allocation-new", `reservations/${reservation.id}/allocations`, (data) => ({ resource_id: data.get("resource_id"), starts_at: reservation.starts_at, ends_at: reservation.ends_at, quantity: Number(data.get("quantity") || 1) }))} className="mt-3 grid grid-cols-[1fr_80px_auto] gap-2">
          <select name="resource_id" required className="field-input"><option value="">Add resource…</option>{resources.filter((resource) => resource.is_active).map((resource) => <option key={resource.id} value={resource.id}>{resource.name} · {resource.type}</option>)}</select>
          <input name="quantity" type="number" min={1} defaultValue={1} aria-label="Allocation quantity" className="field-input" />
          <SubmitIcon pending={pending === "allocation-new"} label="Add allocation" />
        </form>
      </WorkspaceSection>

      <WorkspaceSection title="Deposits" subtitle="Due, paid, and waived deposit milestones.">
        <div className="space-y-2">{deposits.map((deposit) => <div key={deposit.id} className="flex items-center justify-between rounded-xl bg-[#faf8f2] px-4 py-3"><div><p className="text-xs font-bold">{formatMoney(deposit.amount_minor, deposit.currency)}</p><p className="mt-1 text-[10px] text-[var(--muted)]">{deposit.status} · due {displayDate(deposit.due_at)}</p></div>{canManageGuestMoney && deposit.status === "due" ? <button type="button" disabled={pending !== null} onClick={() => mutate(`deposit-${deposit.id}`, `deposits/${deposit.id}/waive`, { method: "POST", body: JSON.stringify({ reason: "Waived by staff from reservation workspace" }) })} className="text-[10px] font-bold text-[var(--red)]">Waive</button> : null}</div>)}</div>
        {canManageGuestMoney ? <form onSubmit={(event) => formMutation(event, "deposit-new", "deposits", (data) => ({ reservation_id: reservation.id, amount_minor: minorAmount(data.get("amount")), due_at: data.get("due_at") ? new Date(String(data.get("due_at"))).toISOString() : null }))} className="mt-3 grid grid-cols-[1fr_1fr_auto] gap-2"><input required name="amount" min="0.01" step="0.01" type="number" placeholder="Amount" className="field-input" /><input name="due_at" type="datetime-local" aria-label="Deposit due date" className="field-input" /><SubmitIcon pending={pending === "deposit-new"} label="Add deposit" /></form> : null}
      </WorkspaceSection>

      <WorkspaceSection title="Payments" subtitle="Record cash, transfer, card, or other receipts.">
        <div className="space-y-2">{payments.map((payment) => <div key={payment.id} className="flex items-center justify-between rounded-xl bg-[#faf8f2] px-4 py-3"><div><p className="text-xs font-bold">{formatMoney(payment.amount_minor, payment.currency)} · {payment.method.replaceAll("_", " ")}</p><p className="mt-1 text-[10px] text-[var(--muted)]">{payment.status} · {displayDate(payment.processed_at)}</p></div>{canManageGuestMoney && payment.status === "pending" ? <button type="button" disabled={pending !== null} onClick={() => mutate(`payment-${payment.id}`, `payments/${payment.id}/reconcile`, { method: "POST", body: JSON.stringify({}) })} className="text-[10px] font-bold text-[var(--forest)]">Reconcile</button> : null}</div>)}</div>
        {canManageGuestMoney ? <form onSubmit={(event) => formMutation(event, "payment-new", "payments", (data) => ({ reservation_id: reservation.id, amount_minor: minorAmount(data.get("amount")), method: data.get("method"), provider_reference: data.get("reference") || null, provider: data.get("reference") ? "manual" : null, captured: data.get("captured") === "on" }))} className="mt-3 grid gap-2 sm:grid-cols-2"><input required name="amount" min="0.01" step="0.01" type="number" placeholder="Amount" className="field-input" /><select name="method" className="field-input"><option value="bank_transfer">Bank transfer</option><option value="cash">Cash</option><option value="card">Card</option><option value="other">Other</option></select><input name="reference" placeholder="Reference (optional)" className="field-input" /><label className="flex items-center gap-2 rounded-xl bg-[#faf8f2] px-3 text-xs font-semibold"><input name="captured" type="checkbox" defaultChecked />Already received</label><button disabled={pending !== null} className="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-[var(--forest)] px-4 text-xs font-bold text-white sm:col-span-2">{pending === "payment-new" ? <LoaderCircle className="size-3.5 animate-spin" /> : <Plus className="size-3.5" />}Record payment</button></form> : null}
      </WorkspaceSection>

      <WorkspaceSection title="Folio" subtitle={`${formatMoney(folioMinor, reservation.currency)} in posted charges and adjustments.`}>
        <div className="space-y-2">{folio.map((line) => <div key={line.id} className="flex items-center justify-between rounded-xl bg-[#faf8f2] px-4 py-3"><div><p className="text-xs font-bold">{line.description}</p><p className="mt-1 text-[10px] text-[var(--muted)]">{line.type} · {displayDate(line.posted_at)}</p></div><span className="font-mono text-xs font-bold">{formatMoney(line.amount_minor, line.currency)}</span></div>)}</div>
        {canManageGuestMoney ? <form onSubmit={(event) => formMutation(event, "folio-new", `reservations/${reservation.id}/folio-lines`, (data) => ({ type: data.get("type"), description: data.get("description"), quantity_thousandths: 1000, unit_amount_minor: minorAmount(data.get("amount")) }))} className="mt-3 grid gap-2 sm:grid-cols-[120px_1fr_120px_auto]"><select name="type" className="field-input"><option value="charge">Charge</option><option value="adjustment">Adjustment</option></select><input required name="description" placeholder="Description" className="field-input" /><input required name="amount" step="0.01" type="number" placeholder="Amount" className="field-input" /><SubmitIcon pending={pending === "folio-new"} label="Post folio line" /></form> : null}
      </WorkspaceSection>

      <WorkspaceSection title="Operational tasks" subtitle="Preparation work connected to this reservation.">
        <div className="space-y-2">{tasks.map((task) => <div key={task.id} className="flex items-center gap-3 rounded-xl bg-[#faf8f2] p-3">{canManageOperations ? <button type="button" disabled={pending !== null} onClick={() => mutate(`task-${task.id}`, `tasks/${task.id}`, { method: "PUT", body: JSON.stringify({ status: task.status === "done" ? "todo" : "done" }) })} aria-label={`${task.status === "done" ? "Reopen" : "Complete"} ${task.title}`} className={task.status === "done" ? "grid size-8 place-items-center rounded-full bg-[var(--forest)] text-white" : "grid size-8 place-items-center rounded-full border border-black/10 bg-white text-black/25"}>{task.status === "done" ? <Check className="size-4" /> : <RotateCcw className="size-3.5" />}</button> : null}<div className="min-w-0 flex-1"><p className={task.status === "done" ? "truncate text-xs font-bold text-[var(--muted)] line-through" : "truncate text-xs font-bold"}>{task.title}</p><p className="mt-1 text-[10px] text-[var(--muted)]">{task.priority} · {displayDate(task.due_at)}</p></div></div>)}</div>
        {canManageOperations ? <form onSubmit={(event) => formMutation(event, "task-new", "tasks", (data) => ({ property_id: reservation.property_id, reservation_id: reservation.id, title: data.get("title"), priority: data.get("priority"), due_at: data.get("due_at") ? new Date(String(data.get("due_at"))).toISOString() : null }))} className="mt-3 grid gap-2 sm:grid-cols-[1fr_110px_1fr_auto]"><input required name="title" placeholder="Task title" className="field-input" /><select name="priority" className="field-input"><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option><option value="low">Low</option></select><input name="due_at" type="datetime-local" aria-label="Task due date" className="field-input" /><SubmitIcon pending={pending === "task-new"} label="Create task" /></form> : null}
      </WorkspaceSection>
    </div>
  </div>;
}

function Summary({ icon, label, value, note }: { icon: React.ReactNode; label: string; value: string; note: string }) {
  return <article className="surface-card rounded-2xl p-4"><div className="flex items-center gap-2 text-[10px] font-bold text-[var(--muted)]"><span className="text-[var(--forest)]">{icon}</span>{label}</div><p className="mt-3 text-sm font-bold">{value}</p><p className="mt-1 text-[10px] text-[var(--muted)]">{note}</p></article>;
}

function WorkspaceSection({ title, subtitle, children }: { title: string; subtitle: string; children: React.ReactNode }) {
  return <section className="surface-card rounded-2xl p-5"><div className="mb-4"><h2 className="text-sm font-bold">{title}</h2><p className="mt-1 text-xs text-[var(--muted)]">{subtitle}</p></div>{children}</section>;
}

function SubmitIcon({ pending, label }: { pending: boolean; label: string }) {
  return <button disabled={pending} aria-label={label} className="grid size-11 place-items-center rounded-xl bg-[var(--forest)] text-white disabled:opacity-50">{pending ? <LoaderCircle className="size-4 animate-spin" /> : <Plus className="size-4" />}</button>;
}
