"use client";

import { useState, type FormEvent } from "react";
import { CheckCircle2, Download, FileCheck2, ShieldAlert } from "lucide-react";
import { useGuestPortal } from "@/components/guest/guest-state";
import styles from "@/components/guest/guest-portal.module.css";

export function DocumentsPanel() {
  const { state, document, reservation, acknowledgeDocument } = useGuestPortal();
  const [accepted, setAccepted] = useState(state.waiver.accepted);
  const [signature, setSignature] = useState(state.waiver.signature);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [saving, setSaving] = useState(false);

  const sign = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!accepted || signature.trim().length < 3) {
      setError("Read and accept the waiver, then enter your full legal name.");
      setMessage("");
      return;
    }

    setSaving(true);
    try {
      await acknowledgeDocument(signature.trim());
      setError("");
      setMessage("Waiver signed securely. A copy is ready for your records.");
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : "Unable to sign right now.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className={styles.grid}>
      <section className={`${styles.card} ${styles.cardPadding}`} aria-labelledby="waiver-heading">
        <div className={styles.documentPreview}>
          <div className={styles.cardHeader} style={{ padding: 0, paddingBottom: "0.8rem" }}>
            <div>
              <span className={styles.statusPill}><FileCheck2 aria-hidden="true" size={13} /> Signature required</span>
              <h2 id="waiver-heading" className={styles.formSectionTitle} style={{ marginTop: "0.7rem" }}>{document?.title ?? "Guest document"}</h2>
              <p className={styles.formSectionDescription}>Version {document?.version ?? "unavailable"} · For {reservation.partyName}</p>
            </div>
            <button className={styles.secondaryButton} type="button" onClick={() => window.print()}>
              <Download aria-hidden="true" size={15} /> Print
            </button>
          </div>
          <div className={styles.documentText} tabIndex={0} aria-label="Scrollable waiver text">
            {(document?.body ?? "No document is currently available.").split("\n\n").map((paragraph, index) => (
              <p key={paragraph} style={index ? { marginTop: "0.8rem" } : undefined}>{paragraph}</p>
            ))}
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
            <button className={styles.primaryButton} type="submit" disabled={state.waiver.accepted || saving || !document}>
              <CheckCircle2 aria-hidden="true" size={16} />
              {state.waiver.accepted ? "Waiver signed" : saving ? "Signing…" : "Sign waiver"}
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
