"use client";

import { useState, type FormEvent } from "react";
import { CheckCircle2, HeartHandshake, Plane, UserRound } from "lucide-react";
import { useGuestPortal } from "@/components/guest/guest-state";
import styles from "@/components/guest/guest-portal.module.css";

type Errors = Partial<Record<"emergencyName" | "emergencyPhone" | "departureReference" | "departureTime" | "medicalConsent", string>>;

export function PreArrivalForm() {
  const { state, savePreArrival } = useGuestPortal();
  const [draft, setDraft] = useState(state);
  const [errors, setErrors] = useState<Errors>({});
  const [message, setMessage] = useState("");
  const [saving, setSaving] = useState(false);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const nextErrors: Errors = {};
    if (!draft.profile.emergencyName.trim()) nextErrors.emergencyName = "Add an emergency contact name.";
    if (!draft.profile.emergencyPhone.trim()) nextErrors.emergencyPhone = "Add an emergency contact phone number.";
    if (!draft.travel.departureReference.trim()) nextErrors.departureReference = "Add a flight, bus, or transfer reference.";
    if (!draft.travel.departureTime) nextErrors.departureTime = "Add your expected departure time.";
    if (!draft.preferences.medicalConsent) nextErrors.medicalConsent = "Confirm that the lodge may share essential information with your field team.";

    setErrors(nextErrors);
    if (Object.keys(nextErrors).length > 0) {
      setMessage("Please review the highlighted details.");
      return;
    }

    setSaving(true);
    try {
      await savePreArrival(draft);
      setDraft((current) => ({ ...current, preArrivalComplete: true }));
      setMessage("Pre-arrival details saved. Your host has the latest information.");
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Unable to save right now.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={submit} noValidate>
      <section className={styles.formSection} aria-labelledby="profile-section">
        <div className={styles.cardHeader} style={{ padding: 0, paddingBottom: "0.85rem" }}>
          <div>
            <h2 id="profile-section" className={styles.formSectionTitle}>About you</h2>
            <p className={styles.formSectionDescription}>How your host can reach and support you during the trip.</p>
          </div>
          <span className={styles.iconBox}><UserRound aria-hidden="true" size={18} /></span>
        </div>
        <div className={styles.fieldGrid}>
          <div className={styles.field}>
            <label htmlFor="preferred-name">Preferred name</label>
            <input
              id="preferred-name"
              autoComplete="given-name"
              value={draft.profile.preferredName}
              onChange={(event) => setDraft((current) => ({ ...current, profile: { ...current.profile, preferredName: event.target.value } }))}
            />
          </div>
          <div className={styles.field}>
            <label htmlFor="guest-email">Email</label>
            <input
              id="guest-email"
              type="email"
              autoComplete="email"
              value={draft.profile.email}
              onChange={(event) => setDraft((current) => ({ ...current, profile: { ...current.profile, email: event.target.value } }))}
            />
          </div>
          <div className={styles.field}>
            <label htmlFor="guest-mobile">Mobile number</label>
            <input
              id="guest-mobile"
              type="tel"
              autoComplete="tel"
              value={draft.profile.mobile}
              onChange={(event) => setDraft((current) => ({ ...current, profile: { ...current.profile, mobile: event.target.value } }))}
            />
          </div>
        </div>
        <div className={styles.fieldGrid}>
          <div className={styles.field}>
            <label htmlFor="emergency-name">Emergency contact</label>
            <input
              id="emergency-name"
              aria-invalid={Boolean(errors.emergencyName)}
              aria-describedby={errors.emergencyName ? "emergency-name-error" : undefined}
              autoComplete="off"
              value={draft.profile.emergencyName}
              onChange={(event) => setDraft((current) => ({ ...current, profile: { ...current.profile, emergencyName: event.target.value } }))}
            />
            {errors.emergencyName ? <span id="emergency-name-error" className={styles.fieldError}>{errors.emergencyName}</span> : null}
          </div>
          <div className={styles.field}>
            <label htmlFor="emergency-phone">Emergency contact phone</label>
            <input
              id="emergency-phone"
              type="tel"
              aria-invalid={Boolean(errors.emergencyPhone)}
              aria-describedby={errors.emergencyPhone ? "emergency-phone-error" : undefined}
              autoComplete="off"
              value={draft.profile.emergencyPhone}
              onChange={(event) => setDraft((current) => ({ ...current, profile: { ...current.profile, emergencyPhone: event.target.value } }))}
            />
            {errors.emergencyPhone ? <span id="emergency-phone-error" className={styles.fieldError}>{errors.emergencyPhone}</span> : null}
          </div>
        </div>
      </section>

      <section className={styles.formSection} aria-labelledby="travel-section">
        <div className={styles.cardHeader} style={{ padding: 0, paddingBottom: "0.85rem" }}>
          <div>
            <h2 id="travel-section" className={styles.formSectionTitle}>Travel details</h2>
            <p className={styles.formSectionDescription}>Live references let us adapt your private transfers if plans shift.</p>
          </div>
          <span className={styles.iconBox}><Plane aria-hidden="true" size={18} /></span>
        </div>
        <fieldset>
          <legend className={styles.legend} style={{ marginTop: "1rem" }}>How are you arriving?</legend>
          <div className={styles.choiceGrid}>
            {(["flight", "car", "other"] as const).map((method) => (
              <label className={styles.choice} key={method}>
                <input
                  type="radio"
                  name="arrival-method"
                  value={method}
                  checked={draft.travel.arrivalMethod === method}
                  onChange={() => setDraft((current) => ({ ...current, travel: { ...current.travel, arrivalMethod: method } }))}
                />
                {method === "flight" ? "Flight" : method === "car" ? "Self-drive" : "Other transfer"}
              </label>
            ))}
          </div>
        </fieldset>
        <div className={styles.fieldGrid}>
          <div className={styles.field}>
            <label htmlFor="arrival-reference">Arrival reference</label>
            <input
              id="arrival-reference"
              value={draft.travel.arrivalReference}
              onChange={(event) => setDraft((current) => ({ ...current, travel: { ...current.travel, arrivalReference: event.target.value } }))}
            />
          </div>
          <div className={styles.field}>
            <label htmlFor="arrival-time">Expected arrival</label>
            <input
              id="arrival-time"
              type="datetime-local"
              value={draft.travel.arrivalTime}
              onChange={(event) => setDraft((current) => ({ ...current, travel: { ...current.travel, arrivalTime: event.target.value } }))}
            />
          </div>
          <div className={styles.field}>
            <label htmlFor="departure-reference">Departure reference</label>
            <input
              id="departure-reference"
              aria-invalid={Boolean(errors.departureReference)}
              aria-describedby={errors.departureReference ? "departure-reference-error" : undefined}
              placeholder="e.g. LA 897"
              value={draft.travel.departureReference}
              onChange={(event) => setDraft((current) => ({ ...current, travel: { ...current.travel, departureReference: event.target.value } }))}
            />
            {errors.departureReference ? <span id="departure-reference-error" className={styles.fieldError}>{errors.departureReference}</span> : null}
          </div>
          <div className={styles.field}>
            <label htmlFor="departure-time">Expected departure</label>
            <input
              id="departure-time"
              type="datetime-local"
              aria-invalid={Boolean(errors.departureTime)}
              aria-describedby={errors.departureTime ? "departure-time-error" : undefined}
              value={draft.travel.departureTime}
              onChange={(event) => setDraft((current) => ({ ...current, travel: { ...current.travel, departureTime: event.target.value } }))}
            />
            {errors.departureTime ? <span id="departure-time-error" className={styles.fieldError}>{errors.departureTime}</span> : null}
          </div>
        </div>
      </section>

      <section className={styles.formSection} aria-labelledby="preferences-section">
        <div className={styles.cardHeader} style={{ padding: 0, paddingBottom: "0.85rem" }}>
          <div>
            <h2 id="preferences-section" className={styles.formSectionTitle}>Dining, health & comfort</h2>
            <p className={styles.formSectionDescription}>Shared only with the team members who need it to care for you.</p>
          </div>
          <span className={styles.iconBox}><HeartHandshake aria-hidden="true" size={18} /></span>
        </div>
        <div className={styles.fieldGrid}>
          <div className={styles.field}>
            <label htmlFor="dietary-style">Dietary preference</label>
            <select
              id="dietary-style"
              value={draft.preferences.dietaryStyle}
              onChange={(event) => setDraft((current) => ({ ...current, preferences: { ...current.preferences, dietaryStyle: event.target.value } }))}
            >
              <option>No preference</option>
              <option>Vegetarian</option>
              <option>Vegan</option>
              <option>Pescatarian</option>
              <option>Gluten-aware</option>
            </select>
          </div>
          <div className={styles.field}>
            <label htmlFor="allergies">Allergies or intolerances</label>
            <input
              id="allergies"
              placeholder="Write ‘none’ if not applicable"
              value={draft.preferences.allergies}
              onChange={(event) => setDraft((current) => ({ ...current, preferences: { ...current.preferences, allergies: event.target.value } }))}
            />
          </div>
        </div>
        <div className={styles.field} style={{ marginTop: "0.95rem" }}>
          <label htmlFor="accessibility">Accessibility, medical or mobility notes</label>
          <textarea
            id="accessibility"
            placeholder="Anything that will help us tailor the stay safely and comfortably"
            value={draft.preferences.accessibility}
            onChange={(event) => setDraft((current) => ({ ...current, preferences: { ...current.preferences, accessibility: event.target.value } }))}
          />
        </div>
        <label className={styles.choice} style={{ marginTop: "0.95rem" }}>
          <input
            type="checkbox"
            checked={draft.preferences.medicalConsent}
            onChange={(event) => setDraft((current) => ({ ...current, preferences: { ...current.preferences, medicalConsent: event.target.checked } }))}
            aria-describedby={errors.medicalConsent ? "medical-consent-error" : undefined}
          />
          I consent to sharing these essential details with my lodge, kitchen and field teams.
        </label>
        {errors.medicalConsent ? <span id="medical-consent-error" className={styles.fieldError}>{errors.medicalConsent}</span> : null}
      </section>

      <div className={styles.formActions}>
        <button className={styles.primaryButton} type="submit" disabled={saving}>
          <CheckCircle2 aria-hidden="true" size={16} /> {saving ? "Saving…" : "Save pre-arrival details"}
        </button>
        <span className={styles.liveMessage} role="status" aria-live="polite">{message}</span>
      </div>
    </form>
  );
}
