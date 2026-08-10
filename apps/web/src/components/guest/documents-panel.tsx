"use client";

import { useState, type FormEvent } from "react";
import { CheckCircle2, Download, FileCheck2, ShieldAlert } from "lucide-react";
import { guestReservation } from "@/data/guest-demo";
import { useGuestPortal } from "@/components/guest/guest-state";
import styles from "@/components/guest/guest-portal.module.css";

export function DocumentsPanel() {
  const { state, updateState } = useGuestPortal();
  const [accepted, setAccepted] = useState(state.waiver.accepted);
  const [signature, setSignature] = useState(state.waiver.signature);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");

  const sign = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!accepted || signature.trim().length < 3) {
      setError("Read and accept the waiver, then enter your full legal name.");
      setMessage("");
      return;
    }

    const signedAt = new Date().toISOString();
    updateState((current) => ({ ...current, waiver: { accepted: true, signature: signature.trim(), signedAt } }));
    setError("");
    setMessage("Waiver signed securely. A copy is ready for your records.");
  };

  return (
    <div className={styles.grid}>
      <section className={`${styles.card} ${styles.cardPadding}`} aria-labelledby="waiver-heading">
        <div className={styles.documentPreview}>
          <div className={styles.cardHeader} style={{ padding: 0, paddingBottom: "0.8rem" }}>
            <div>
              <span className={styles.statusPill}><FileCheck2 aria-hidden="true" size={13} /> Signature required</span>
              <h2 id="waiver-heading" className={styles.formSectionTitle} style={{ marginTop: "0.7rem" }}>Outdoor activity waiver</h2>
              <p className={styles.formSectionDescription}>Version 3.2 · For {guestReservation.partyName}</p>
            </div>
            <button className={styles.secondaryButton} type="button" onClick={() => window.print()}>
              <Download aria-hidden="true" size={15} /> Print
            </button>
          </div>
          <div className={styles.documentText} tabIndex={0} aria-label="Scrollable waiver text">
            <p><strong>Participation and informed consent.</strong> I understand that hiking, riding and travel in a remote mountain environment involve changing weather, uneven terrain and other inherent risks.</p>
            <p style={{ marginTop: "0.8rem" }}>I agree to follow the reasonable safety directions of Estancia Viento Sur&apos;s qualified guides, to disclose information material to safe participation, and to choose an alternative activity if a guide determines that conditions require it.</p>
            <p style={{ marginTop: "0.8rem" }}>I authorize the lodge to coordinate emergency care when I cannot reasonably provide instructions. This document does not waive rights that cannot lawfully be waived and does not release the operator from liability for gross negligence or intentional misconduct.</p>
            <p style={{ marginTop: "0.8rem" }}>I may ask my host any question before signing. My electronic signature is associated with my private reservation session, the document version and a timestamp.</p>
          </div>
        </div>

        <form onSubmit={sign} style={{ marginTop: "1rem" }} noValidate>
          <label className={styles.choice}>
            <input type="checkbox" checked={accepted} onChange={(event) => setAccepted(event.target.checked)} />
            I have read, understood and agree to the outdoor activity waiver.
          </label>
          <div className={styles.field} style={{ marginTop: "0.9rem" }}>
            <label htmlFor="legal-signature">Electronic signature · full legal name</label>
            <input
              id="legal-signature"
              autoComplete="name"
              aria-invalid={Boolean(error)}
              aria-describedby={error ? "signature-error" : undefined}
              value={signature}
              onChange={(event) => setSignature(event.target.value)}
              disabled={state.waiver.accepted}
            />
            {error ? <span id="signature-error" className={styles.fieldError}>{error}</span> : null}
          </div>
          <div className={styles.formActions}>
            <button className={styles.primaryButton} type="submit" disabled={state.waiver.accepted}>
              <CheckCircle2 aria-hidden="true" size={16} />
              {state.waiver.accepted ? "Waiver signed" : "Sign waiver"}
            </button>
            <span className={styles.liveMessage} role="status" aria-live="polite">{message}</span>
          </div>
        </form>
      </section>

      <aside className={`${styles.card} ${styles.cardPadding}`} aria-labelledby="document-security-heading">
        <span className={styles.iconBox}><ShieldAlert aria-hidden="true" size={18} /></span>
        <h2 id="document-security-heading" className={styles.cardTitle} style={{ marginTop: "0.85rem" }}>A verifiable signature</h2>
        <p className={styles.cardDescription}>
          In production, the signed record includes the exact document hash, authenticated portal session, timestamp and consent audit trail.
        </p>
        {state.waiver.signedAt ? (
          <div className={styles.notice} style={{ marginTop: "1rem" }}>
            <CheckCircle2 aria-hidden="true" size={17} />
            <span>Signed by {state.waiver.signature} on {new Date(state.waiver.signedAt).toLocaleString()}.</span>
          </div>
        ) : null}
      </aside>
    </div>
  );
}
