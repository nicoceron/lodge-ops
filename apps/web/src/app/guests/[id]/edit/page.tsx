import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { AppShell } from "@/components/app-shell";
import { GuestForm } from "@/components/staff/guest-form";
import { demoModeEnabled } from "@/data/api-client";
import { getGuest, type GuestDto } from "@/data/staff-api";
import { guests as demoGuests } from "@/lib/demo-data";

export const metadata: Metadata = { title: "Edit guest" };
export default async function EditGuestPage({ params }: { params: Promise<{ id: string }> }) { const { id } = await params; let guest: GuestDto; if (demoModeEnabled) { const record = demoGuests[Number(id.replace("demo-", ""))]; if (!record) notFound(); guest = { id, first_name: record.name.split(" ")[0], last_name: record.name.split(" ").slice(1).join(" "), full_name: record.name, email: record.email, phone: null, document_type: null, document_number: null, language: "en", preferences: Object.fromEntries(record.preferences.map((preference) => [preference, true])), marketing_consent: true, created_at: new Date().toISOString(), updated_at: new Date().toISOString() }; } else { try { guest = (await getGuest(id)).data; } catch { notFound(); } } return <AppShell eyebrow="Guest profile" title={`Edit ${guest.full_name}`} description="Keep contact, consent, and service preferences accurate for the whole lodge team."><GuestForm guest={guest} demo={demoModeEnabled} /></AppShell>; }
