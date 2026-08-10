"use client";

import { Download, FileText, Info, LockKeyhole } from "lucide-react";
import { formatMoney, guestReservation } from "@/data/guest-demo";
import styles from "@/components/guest/guest-portal.module.css";

export function FolioPanel() {
  const charges = guestReservation.folio.filter((item) => item.amountMinor > 0).reduce((sum, item) => sum + item.amountMinor, 0);
  const payments = guestReservation.folio.filter((item) => item.amountMinor < 0).reduce((sum, item) => sum + Math.abs(item.amountMinor), 0);
  const currentBalance = charges - payments;

  return (
    <div className={styles.grid}>
      <section className={`${styles.card} ${styles.cardPadding}`} aria-labelledby="folio-heading">
        <div className={styles.cardHeader} style={{ padding: 0, paddingBottom: "0.9rem" }}>
          <div>
            <span className={styles.statusPill}><FileText aria-hidden="true" size={13} /> Live statement</span>
            <h2 id="folio-heading" className={styles.formSectionTitle} style={{ marginTop: "0.7rem" }}>Guest folio</h2>
            <p className={styles.formSectionDescription}>Reservation {guestReservation.reservationCode} · amounts in USD</p>
          </div>
          <button className={styles.secondaryButton} type="button" onClick={() => window.print()}>
            <Download aria-hidden="true" size={15} /> Print
          </button>
        </div>

        <div style={{ overflowX: "auto" }}>
          <table className={styles.folioTable}>
            <thead>
              <tr><th>Date</th><th>Description</th><th>Amount</th></tr>
            </thead>
            <tbody>
              {guestReservation.folio.map((line) => (
                <tr key={`${line.date}-${line.description}`}>
                  <td>{line.date}</td>
                  <td>{line.description}</td>
                  <td>{formatMoney(line.amountMinor)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className={styles.folioTotals}>
          <div className={styles.folioTotalRow}><span>Charges</span><strong>{formatMoney(charges)}</strong></div>
          <div className={styles.folioTotalRow}><span>Payments received</span><strong>−{formatMoney(payments)}</strong></div>
          <div className={`${styles.folioTotalRow} ${styles.folioTotalRowStrong}`}><span>Balance</span><span>{formatMoney(currentBalance)}</span></div>
        </div>
      </section>

      <aside className={`${styles.card} ${styles.cardPadding}`} aria-labelledby="final-folio-heading">
        <span className={styles.iconBox}><LockKeyhole aria-hidden="true" size={18} /></span>
        <h2 id="final-folio-heading" className={styles.cardTitle} style={{ marginTop: "0.85rem" }}>Final after check-out</h2>
        <p className={styles.cardDescription}>This live statement includes confirmed reservation charges and payments. Your tax-valid final folio is locked after departure and remains available here.</p>
        <div className={styles.notice} style={{ marginTop: "1rem" }}>
          <Info aria-hidden="true" size={18} />
          <span>Extra activities or lodge purchases appear only after you approve them. Questions? Your host can explain any line without altering the audit trail.</span>
        </div>
      </aside>
    </div>
  );
}
