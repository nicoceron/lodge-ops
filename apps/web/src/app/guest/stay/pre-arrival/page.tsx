import type { Metadata } from "next";
import { ClipboardCheck } from "lucide-react";
import { PreArrivalForm } from "@/components/guest/pre-arrival-form";
import styles from "@/components/guest/guest-portal.module.css";

export const metadata: Metadata = { title: "Pre-arrival" };

export default function PreArrivalPage() {
  return (
    <>
      <div className={styles.pageHeader}>
        <div><p className={styles.eyebrow}>Before you arrive</p><h1 className={styles.pageTitle}>Help us prepare around you</h1><p className={styles.pageDescription}>One calm form covers contact, travel, dining, accessibility and essential consent details.</p></div>
        <span className={styles.statusPill}><ClipboardCheck aria-hidden="true" size={14} /> About 4 minutes</span>
      </div>
      <section className={`${styles.card} ${styles.cardPadding}`}><PreArrivalForm /></section>
    </>
  );
}
