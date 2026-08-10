import type { Metadata } from "next";
import { SurveyForm } from "@/components/guest/survey-form";
import styles from "@/components/guest/guest-portal.module.css";

export const metadata: Metadata = { title: "Your feedback" };

export default function SurveyPage() {
  return <><div className={styles.pageHeader}><div><p className={styles.eyebrow}>After your stay</p><h1 className={styles.pageTitle}>How did Patagonia feel?</h1><p className={styles.pageDescription}>A short, private survey accepted once after departure.</p></div></div><SurveyForm /></>;
}
