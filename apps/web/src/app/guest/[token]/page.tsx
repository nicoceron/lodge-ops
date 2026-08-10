import { GuestOverview } from "@/components/guest/guest-overview";

export default async function GuestOverviewPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  return <GuestOverview token={token} />;
}
