"use client";

import { useState, type FormEvent } from "react";
import { CheckCircle2, Heart, MessageSquareHeart, Send } from "lucide-react";
import { useGuestPortal } from "@/components/guest/guest-state";
import styles from "@/components/guest/guest-portal.module.css";

function RatingField({
  legend,
  name,
  value,
  onChange,
}: {
  legend: string;
  name: string;
  value: number;
  onChange: (rating: number) => void;
}) {
  return (
    <fieldset>
      <legend className={styles.legend}>{legend}</legend>
      <div className={styles.ratingGroup}>
        {[1, 2, 3, 4, 5].map((rating) => (
          <span className={styles.ratingChoice} key={rating}>
            <input
              id={`${name}-${rating}`}
              name={name}
              type="radio"
              value={rating}
              checked={value === rating}
              onChange={() => onChange(rating)}
            />
            <label htmlFor={`${name}-${rating}`} aria-label={`${rating} out of 5`}>{rating}</label>
          </span>
        ))}
      </div>
    </fieldset>
  );
}

export function SurveyForm() {
  const { state, submitSurvey } = useGuestPortal();
  const [stayRating, setStayRating] = useState(state.survey.stayRating);
  const [guideRating, setGuideRating] = useState(state.survey.guideRating);
  const [comment, setComment] = useState(state.survey.comment);
  const [shareWithTeam, setShareWithTeam] = useState(true);
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!stayRating || !guideRating) {
      setError("Choose a rating for both your stay and guiding experience.");
      return;
    }
    setSaving(true);
    try {
      await submitSurvey({ stayRating, guideRating, comment, shareWithTeam });
      setError("");
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : "Unable to submit feedback right now.");
    } finally {
      setSaving(false);
    }
  };

  if (state.survey.submitted) {
    return (
      <section className={`${styles.card} ${styles.thankYou}`} aria-labelledby="thanks-heading">
        <div>
          <span className={styles.iconBox} style={{ marginInline: "auto", width: "3rem", height: "3rem" }}>
            <Heart aria-hidden="true" size={21} />
          </span>
          <h2 id="thanks-heading" className={styles.pageTitle} style={{ marginTop: "1rem" }}>Thank you, Alex.</h2>
          <p className={styles.pageDescription}>Your feedback is safely with the lodge team. It helps us care for the next journey even better.</p>
          <span className={styles.statusPill} style={{ marginTop: "1rem" }}><CheckCircle2 aria-hidden="true" size={14} /> Feedback received</span>
        </div>
      </section>
    );
  }

  return (
    <div className={styles.grid}>
      <form className={`${styles.card} ${styles.cardPadding}`} onSubmit={submit} noValidate>
        <div className={styles.cardHeader} style={{ padding: 0, paddingBottom: "0.9rem" }}>
          <div>
            <h2 className={styles.formSectionTitle}>A quiet moment of reflection</h2>
            <p className={styles.formSectionDescription}>About two minutes. Ratings are required; written feedback is always optional.</p>
          </div>
          <span className={styles.iconBox}><MessageSquareHeart aria-hidden="true" size={18} /></span>
        </div>

        <div className={styles.formSection} style={{ marginTop: "1.2rem" }}>
          <RatingField legend="How would you rate your stay overall?" name="stay-rating" value={stayRating} onChange={setStayRating} />
        </div>
        <div className={styles.formSection}>
          <RatingField legend="How would you rate the guiding experience?" name="guide-rating" value={guideRating} onChange={setGuideRating} />
        </div>
        <div className={styles.formSection}>
          <div className={styles.field}>
            <label htmlFor="survey-comment">What should we keep, change or remember?</label>
            <textarea id="survey-comment" value={comment} onChange={(event) => setComment(event.target.value)} placeholder="Tell us in your own words…" />
          </div>
          <label className={styles.choice} style={{ marginTop: "0.8rem" }}>
            <input type="checkbox" checked={shareWithTeam} onChange={(event) => setShareWithTeam(event.target.checked)} />
            Share my note with the host and guide teams who cared for our stay.
          </label>
        </div>

        {error ? <p className={styles.fieldError} role="alert" style={{ marginTop: "1rem" }}>{error}</p> : null}
        <div className={styles.formActions}>
          <button className={styles.primaryButton} type="submit" disabled={saving}>
            <Send aria-hidden="true" size={15} /> {saving ? "Sending…" : "Send private feedback"}
          </button>
        </div>
      </form>

      <aside className={`${styles.card} ${styles.cardPadding}`} aria-labelledby="feedback-privacy-heading">
        <span className={styles.iconBox}><Heart aria-hidden="true" size={18} /></span>
        <h2 id="feedback-privacy-heading" className={styles.cardTitle} style={{ marginTop: "0.85rem" }}>Candid by design</h2>
        <p className={styles.cardDescription}>Your response belongs to this reservation and is visible only to authorized lodge leaders. We do not publish a review or marketing quote without asking separately.</p>
      </aside>
    </div>
  );
}
