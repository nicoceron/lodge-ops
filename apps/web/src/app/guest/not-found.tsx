import Link from "next/link";
import { LockKeyhole } from "lucide-react";
import styles from "@/components/guest/guest-portal.module.css";

export default function GuestNotFound() {
  return (
    <main className={styles.portal} style={{ display: "grid", minHeight: "100vh", placeItems: "center", padding: "1rem" }}>
      <section className={`${styles.card} ${styles.thankYou}`} style={{ maxWidth: "35rem" }}>
        <div>
          <span className={styles.iconBox} style={{ marginInline: "auto" }}><LockKeyhole aria-hidden="true" size={18} /></span>
          <h1 className={styles.pageTitle} style={{ marginTop: "1rem" }}>This private link is unavailable</h1>
          <p className={styles.pageDescription}>It may have expired or already been replaced. For your privacy, reservation details cannot be recovered by surname.</p>
          <Link href="mailto:stay@vientosur.example" className={styles.actionLink} style={{ marginTop: "1rem" }}>Contact the lodge</Link>
        </div>
      </section>
    </main>
  );
}
