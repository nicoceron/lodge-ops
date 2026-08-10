import type { Metadata } from "next";
import { DocumentsPanel } from "@/components/guest/documents-panel";
import styles from "@/components/guest/guest-portal.module.css";

export const metadata: Metadata = { title: "Documents" };

export default function DocumentsPage() {
  return <><div className={styles.pageHeader}><div><p className={styles.eyebrow}>Documents & consent</p><h1 className={styles.pageTitle}>Clear before you sign</h1><p className={styles.pageDescription}>Read the exact active document and create an auditable acknowledgement tied to this private reservation.</p></div></div><DocumentsPanel /></>;
}
