import type { Metadata } from "next";
import { FolioPanel } from "@/components/guest/folio-panel";
import styles from "@/components/guest/guest-portal.module.css";

export const metadata: Metadata = { title: "Final folio" };

export default function FolioPage() {
  return (
    <>
      <div className={styles.pageHeader}>
        <div>
          <p className={styles.eyebrow}>Your statement</p>
          <h1 className={styles.pageTitle}>Every charge, explained</h1>
          <p className={styles.pageDescription}>Follow confirmed charges and receipts during your stay, then return to the same private link for the immutable final folio.</p>
        </div>
      </div>
      <FolioPanel />
    </>
  );
}
