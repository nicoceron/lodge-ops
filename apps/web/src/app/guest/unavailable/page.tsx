import type { Metadata } from "next";
import Link from "next/link";
import { LockKeyhole } from "lucide-react";
import styles from "@/components/guest/guest-portal.module.css";

export const metadata: Metadata = {
  title: "Private link unavailable",
  referrer: "no-referrer",
  robots: { index: false, follow: false, nocache: true },
};

export default function GuestUnavailablePage() {
  return (
    <main className={styles.portal} style={{ display: "grid", minHeight: "100vh", placeItems: "center", padding: "1rem" }}>
      <section className={`${styles.card} ${styles.thankYou}`} style={{ maxWidth: "35rem" }}><div>
        <span className={styles.iconBox} style={{ marginInline: "auto" }}><LockKeyhole aria-hidden="true" size={18} /></span>
        <h1 className={styles.pageTitle} style={{ marginTop: "1rem" }}>This private link is unavailable</h1>
        <p className={styles.pageDescription}>It may have expired, been used, or been revoked. Reservation details cannot be recovered by surname.</p>
        <Link href="mailto:stay@vientosur.example" className={styles.actionLink} style={{ marginTop: "1rem" }}>Contact the lodge</Link>
      </div></section>
    </main>
  );
}
