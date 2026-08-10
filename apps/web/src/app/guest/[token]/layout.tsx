import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { GuestShell } from "@/components/guest/guest-shell";
import { GuestPortalProvider } from "@/components/guest/guest-state";
import { DEMO_GUEST_TOKEN } from "@/data/guest-demo";

export const metadata: Metadata = {
  title: {
    default: "Your stay · Estancia Viento Sur",
    template: "%s · Estancia Viento Sur",
  },
  description: "Your private Estancia Viento Sur reservation center.",
  referrer: "no-referrer",
  robots: { index: false, follow: false, nocache: true },
};

export default async function GuestLayout({
  children,
  params,
}: {
  children: React.ReactNode;
  params: Promise<{ token: string }>;
}) {
  const { token } = await params;
  if (token !== DEMO_GUEST_TOKEN) notFound();

  return (
    <GuestPortalProvider token={token}>
      <GuestShell token={token}>{children}</GuestShell>
    </GuestPortalProvider>
  );
}
