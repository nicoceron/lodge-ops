import type { Metadata } from "next";
import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import { KeyRound, LockKeyhole } from "lucide-react";
import styles from "@/components/guest/guest-portal.module.css";
import {
  exchangeGuestPortalToken,
  guestPortalCookie,
  guestPortalDemoEnabled,
} from "@/data/guest-api";
import { DEMO_GUEST_TOKEN } from "@/data/guest-demo";

export const metadata: Metadata = {
  title: "Open your private stay",
  referrer: "no-referrer",
  robots: { index: false, follow: false, nocache: true },
};

async function unlockGuestPortal(token: string) {
  "use server";

  if (guestPortalDemoEnabled()) {
    if (token !== DEMO_GUEST_TOKEN) redirect("/guest/unavailable");
    redirect("/guest/stay");
  }

  let exchange = null;
  try {
    exchange = await exchangeGuestPortalToken(token);
  } catch {
    redirect("/guest/unavailable");
  }
  if (!exchange) redirect("/guest/unavailable");

  const cookieStore = await cookies();
  cookieStore.set(guestPortalCookie, exchange.access_token, {
    httpOnly: true,
    sameSite: "strict",
    secure: process.env.NODE_ENV === "production" && process.env.GUEST_PORTAL_COOKIE_SECURE !== "false",
    path: "/guest",
    expires: new Date(exchange.expires_at),
  });
  redirect("/guest/stay");
}

export default async function GuestAccessPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  const unlock = unlockGuestPortal.bind(null, token);

  return (
    <main className={styles.portal} style={{ display: "grid", minHeight: "100vh", placeItems: "center", padding: "1rem" }}>
      <section className={`${styles.card} ${styles.thankYou}`} style={{ maxWidth: "35rem" }}>
        <div>
          <span className={styles.iconBox} style={{ marginInline: "auto" }}><LockKeyhole aria-hidden="true" size={18} /></span>
          <p className={styles.eyebrow} style={{ marginTop: "1rem" }}>Private reservation center</p>
          <h1 className={styles.pageTitle} style={{ marginTop: "0.5rem" }}>Your stay is one step away</h1>
          <p className={styles.pageDescription}>Confirm to exchange this one-time link for a protected browser session. The link cannot be reused after this step.</p>
          <form action={unlock} style={{ marginTop: "1.1rem" }}>
            <button className={styles.primaryButton} type="submit"><KeyRound aria-hidden="true" size={16} /> Open my stay</button>
          </form>
        </div>
      </section>
    </main>
  );
}
