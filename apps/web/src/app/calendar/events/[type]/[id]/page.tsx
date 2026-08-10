import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { ArrowLeft } from "lucide-react";
import { AppShell } from "@/components/app-shell";
import { CalendarEventEditor } from "@/components/staff/calendar-event-editor";
import { LodgeApiError } from "@/data/api-client";
import { getResourceBlock, getServiceOccurrence, listPrograms, listResources } from "@/data/staff-api";

export const metadata: Metadata = { title: "Calendar event" };

export default async function CalendarEventPage({ params }: { params: Promise<{ type: string; id: string }> }) {
  const { type, id } = await params;
  if (!["activity", "resource_block"].includes(type)) notFound();
  let loaded;
  try {
    if (type === "activity") {
      const [eventResponse, programResponse, resourceResponse] = await Promise.all([getServiceOccurrence(id), listPrograms(), listResources()]);
      loaded = { kind: "activity" as const, event: eventResponse.data, programs: programResponse.data, resources: resourceResponse.data };
    } else {
      const [eventResponse, programResponse, resourceResponse] = await Promise.all([getResourceBlock(id), listPrograms(), listResources()]);
      loaded = { kind: "resource_block" as const, event: eventResponse.data, programs: programResponse.data, resources: resourceResponse.data };
    }
  } catch (reason) {
    if (reason instanceof LodgeApiError && reason.status === 404) notFound();
    throw reason;
  }

  return <Shell>{loaded.kind === "activity"
    ? <CalendarEventEditor occurrence={loaded.event} programs={loaded.programs} resources={loaded.resources} />
    : <CalendarEventEditor block={loaded.event} programs={loaded.programs} resources={loaded.resources} />}</Shell>;
}

function Shell({ children }: { children: React.ReactNode }) {
  return <AppShell eyebrow="Master calendar" title="Edit calendar event" description="Adjust the timing and operational details that feed the unified resource plan."><Link href="/calendar" className="mb-4 inline-flex items-center gap-2 text-xs font-bold text-[var(--forest)]"><ArrowLeft className="size-3.5" />Back to calendar</Link>{children}</AppShell>;
}
