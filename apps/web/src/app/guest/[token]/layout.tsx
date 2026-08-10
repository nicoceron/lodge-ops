import { redirect } from "next/navigation";

export default async function GuestLayout({
  params,
}: {
  params: Promise<{ token: string }>;
}) {
  const { token } = await params;
  redirect(`/guest/access/${encodeURIComponent(token)}`);
}
