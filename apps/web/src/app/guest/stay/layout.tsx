import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { GuestShell } from "@/components/guest/guest-shell";
import { GuestPortalProvider } from "@/components/guest/guest-state";
import { getGuestPortalPageData } from "@/data/guest-api";

export const metadata: Metadata = {
  title: {
    default: "Your stay",
    template: "%s · Your stay",
  },
  description: "Your private lodge reservation center.",
  referrer: "no-referrer",
  robots: { index: false, follow: false, nocache: true },
};

export default async function GuestStayLayout({ children }: { children: React.ReactNode }) {
  const portal = await getGuestPortalPageData();
  if (!portal) redirect("/guest/unavailable");

  return (
    <GuestPortalProvider
      token={`${portal.mode}:${portal.reservation.reservationCode}`}
      mode={portal.mode}
      initialState={portal.state}
      reservation={portal.reservation}
      document={portal.document}
    >
      <GuestShell basePath="/guest/stay">{children}</GuestShell>
    </GuestPortalProvider>
  );
}
