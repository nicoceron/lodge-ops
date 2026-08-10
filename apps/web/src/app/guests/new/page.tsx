import type { Metadata } from "next";
import { AppShell } from "@/components/app-shell";
import { GuestForm } from "@/components/staff/guest-form";
import { demoModeEnabled } from "@/data/api-client";

export const metadata: Metadata = { title: "Add guest" };
export default function NewGuestPage() { return <AppShell eyebrow="Guests & CRM" title="Add a guest" description="Create one tenant-owned profile that can be reused across proposals, reservations, and future stays."><GuestForm demo={demoModeEnabled} /></AppShell>; }
