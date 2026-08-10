"use client";

import { useRef, useState } from "react";
import { CheckCircle2, Clock3, Copy, Landmark, ShieldCheck, UploadCloud } from "lucide-react";
import { formatMoney, guestReservation } from "@/data/guest-demo";
import { useGuestPortal } from "@/components/guest/guest-state";
import styles from "@/components/guest/guest-portal.module.css";

const bankDetails = [
  ["Beneficiary", "Estancia Viento Sur SA"],
  ["Bank", "Banco Patagonia"],
  ["SWIFT / BIC", "BAPGARBA"],
  ["Account", "USD · •••• 4428"],
  ["Reference", guestReservation.reservationCode],
];

export function PaymentPanel() {
  const { state, updateState } = useGuestPortal();
  const [message, setMessage] = useState("");
  const fileInput = useRef<HTMLInputElement>(null);

  const copyReference = async () => {
    try {
      await navigator.clipboard.writeText(guestReservation.reservationCode);
      setMessage("Payment reference copied.");
    } catch {
      setMessage(`Use reference ${guestReservation.reservationCode}.`);
    }
  };

  const fileSelected = () => {
    const file = fileInput.current?.files?.[0];
    if (!file) return;
    updateState((current) => ({
      ...current,
      paymentEvidence: { fileName: file.name, status: "review-pending" },
    }));
    setMessage(`${file.name} attached for secure review.`);
  };

  return (
    <div className={styles.grid}>
      <section className={`${styles.card} ${styles.cardPadding}`} aria-labelledby="payment-heading">
        <span className={styles.statusPill}><Clock3 aria-hidden="true" size={13} /> Due 10 August</span>
        <h2 id="payment-heading" className={styles.paymentAmount} style={{ marginTop: "0.75rem" }}>
          {formatMoney(guestReservation.balanceMinor)}
        </h2>
        <p className={styles.paymentMeta}>Remaining balance · bank transfer</p>

        <div className={styles.bankDetails} aria-label="Bank transfer instructions">
          {bankDetails.map(([label, value]) => (
            <div className={styles.bankRow} key={label}>
              <span className={styles.bankLabel}>{label}</span>
              <span className={styles.bankValue}>{value}</span>
            </div>
          ))}
        </div>

        <div className={styles.formActions}>
          <button className={styles.secondaryButton} type="button" onClick={copyReference}>
            <Copy aria-hidden="true" size={15} /> Copy reference
          </button>
          <span className={styles.liveMessage} role="status" aria-live="polite">{message}</span>
        </div>

        <div className={styles.notice} style={{ marginTop: "1rem" }}>
          <ShieldCheck aria-hidden="true" size={18} />
          <span>Bank instructions are shown only inside your private reservation session. Your host will never email replacement account details.</span>
        </div>
      </section>

      <aside className={`${styles.card} ${styles.cardPadding}`} aria-labelledby="evidence-heading">
        <span className={styles.iconBox}><Landmark aria-hidden="true" size={18} /></span>
        <h2 id="evidence-heading" className={styles.cardTitle} style={{ marginTop: "0.85rem" }}>Transfer evidence</h2>
        <p className={styles.cardDescription}>Attach a PDF or image after sending the transfer. This does not mark a payment as received; finance verifies it independently.</p>

        {state.paymentEvidence.status === "review-pending" ? (
          <div className={styles.notice} style={{ marginTop: "1rem" }}>
            <Clock3 aria-hidden="true" size={18} />
            <span><strong>{state.paymentEvidence.fileName}</strong> is awaiting finance review. We usually respond within one business day.</span>
          </div>
        ) : state.paymentEvidence.status === "accepted" ? (
          <div className={styles.notice} style={{ marginTop: "1rem" }}>
            <CheckCircle2 aria-hidden="true" size={18} /> Evidence accepted and balance reconciled.
          </div>
        ) : (
          <div className={styles.uploadZone} style={{ marginTop: "1rem" }}>
            <UploadCloud aria-hidden="true" size={28} color="var(--moss)" />
            <strong style={{ marginTop: "0.6rem", fontSize: "0.76rem" }}>Attach transfer confirmation</strong>
            <span className={styles.cardDescription}>PDF, JPG or PNG · up to 10 MB</span>
            <input
              ref={fileInput}
              type="file"
              aria-label="Choose transfer evidence"
              accept="application/pdf,image/jpeg,image/png"
              onChange={fileSelected}
            />
          </div>
        )}
      </aside>
    </div>
  );
}
