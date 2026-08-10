import type { Metadata } from "next";
import { PaymentPanel } from "@/components/guest/payment-panel";
import styles from "@/components/guest/guest-portal.module.css";

export const metadata: Metadata = { title: "Payments" };

export default function PaymentsPage() {
  return (
    <>
      <div className={styles.pageHeader}>
        <div>
          <p className={styles.eyebrow}>Payments</p>
          <h1 className={styles.pageTitle}>Unambiguous payment details</h1>
          <p className={styles.pageDescription}>A protected source of truth for instructions, evidence and finance review—without treating an uploaded receipt as settled money.</p>
        </div>
      </div>
      <PaymentPanel />
    </>
  );
}
