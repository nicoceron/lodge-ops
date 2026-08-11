"use client";

import { useState, type FormEvent } from "react";
import { CheckCircle2, LoaderCircle } from "lucide-react";
import { useRouter } from "next/navigation";
import { staffMutation, StaffMutationError } from "@/data/staff-client";
import type { GuestDto } from "@/data/staff-api";

export function GuestForm({ guest, demo = false }: { guest?: GuestDto; demo?: boolean }) {
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [error, setError] = useState("");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setError(""); setFieldErrors({});
    if (demo) { setError("Demo mode is read-only. Sign in to a live tenant to save guest profiles."); return; }
    setPending(true);
    const data = new FormData(event.currentTarget);
    const preferences = Object.fromEntries(String(data.get("preferences") ?? "").split("\n").map((line) => line.split(":", 2).map((part) => part.trim())).filter((parts) => parts.length === 2 && parts[0]));
    const payload = {
      first_name: data.get("first_name"), last_name: data.get("last_name") || null,
      email: data.get("email") || null, phone: data.get("phone") || null,
      language: data.get("language") || null, preferences,
      marketing_consent: data.get("marketing_consent") === "on",
    };
    try {
      const response = await staffMutation<{ data: GuestDto }>(guest ? `guests/${guest.id}` : "guests", { method: guest ? "PUT" : "POST", body: JSON.stringify(payload) });
      router.push(`/guests/${response.data.id}`); router.refresh();
    } catch (reason) {
      if (reason instanceof StaffMutationError) setFieldErrors(reason.errors);
      setError(reason instanceof Error ? reason.message : "Unable to save this guest."); setPending(false);
    }
  }

  const preferences = Object.entries(guest?.preferences ?? {}).map(([key, value]) => `${key}: ${String(value)}`).join("\n");
  const field = "h-11 w-full rounded-xl border border-black/10 bg-white px-3 text-sm shadow-sm";
  return <form onSubmit={submit} className="surface-card rounded-2xl p-5 sm:p-7" noValidate>
    <div className="grid gap-5 sm:grid-cols-2">
      <Field label="First name" name="first_name" defaultValue={guest?.first_name} required errors={fieldErrors.first_name} className={field} />
      <Field label="Last name" name="last_name" defaultValue={guest?.last_name ?? ""} errors={fieldErrors.last_name} className={field} />
      <Field label="Email" name="email" type="email" defaultValue={guest?.email ?? ""} errors={fieldErrors.email} className={field} />
      <Field label="Phone" name="phone" type="tel" defaultValue={guest?.phone ?? ""} errors={fieldErrors.phone} className={field} />
      <label className="text-xs font-bold">Language<select name="language" defaultValue={guest?.language ?? ""} className={`${field} mt-2`}><option value="">Not specified</option><option value="en">English</option><option value="es">Español</option><option value="pt">Português</option><option value="fr">Français</option></select></label>
      <label className="flex items-center gap-2 self-end pb-3 text-xs font-semibold"><input name="marketing_consent" type="checkbox" defaultChecked={guest?.marketing_consent} className="size-4 accent-[var(--forest)]" />Marketing consent recorded</label>
    </div>
    <label className="mt-5 block text-xs font-bold">Preferences and restrictions<textarea name="preferences" defaultValue={preferences} rows={6} placeholder={'dietary: Gluten-free\nlanguage: Spanish\naccessibility: Ground-floor room'} className="mt-2 w-full rounded-xl border border-black/10 bg-white p-3 text-sm shadow-sm" /><span className="mt-1 block text-[10px] font-normal text-[var(--muted)]">One “label: value” per line.</span></label>
    {error ? <p role="alert" className="mt-5 rounded-xl bg-[var(--red-soft)] px-4 py-3 text-xs text-[var(--red)]">{error}</p> : null}
    <div className="mt-6 flex justify-end"><button disabled={pending} className="inline-flex h-11 items-center gap-2 rounded-xl bg-[var(--forest)] px-5 text-sm font-bold text-white disabled:opacity-60">{pending ? <LoaderCircle className="size-4 animate-spin" /> : <CheckCircle2 className="size-4" />}{pending ? "Saving…" : guest ? "Save guest" : "Create guest"}</button></div>
  </form>;
}

function Field({ label, name, type = "text", defaultValue, required, errors, className }: { label: string; name: string; type?: string; defaultValue?: string | null; required?: boolean; errors?: string[]; className: string }) {
  return <label className="text-xs font-bold">{label}<input name={name} type={type} defaultValue={defaultValue ?? ""} required={required} className={`${className} mt-2`} />{errors?.map((message) => <span key={message} className="mt-1 block text-[10px] text-[var(--red)]">{message}</span>)}</label>;
}
