import styles from "@/components/guest/guest-portal.module.css";

export default function GuestLoading() {
  return (
    <div className={styles.grid} aria-label="Loading your reservation">
      <div className={`${styles.card} ${styles.cardPadding}`} style={{ minHeight: "18rem" }} />
      <div className={`${styles.card} ${styles.cardPadding}`} style={{ minHeight: "12rem" }} />
    </div>
  );
}
