"use client";

import Link from "next/link";
import {
  ArrowRight,
  BedDouble,
  CheckCircle2,
  Circle,
  Compass,
  MapPin,
  Phone,
  ShieldCheck,
  Sparkles,
  Users,
} from "lucide-react";
import { guestReservation } from "@/data/guest-demo";
import { useGuestPortal } from "@/components/guest/guest-state";
import styles from "@/components/guest/guest-portal.module.css";

export function GuestOverview({ token }: { token: string }) {
  const { completion, completedCount, completionPercent } = useGuestPortal();
  const readinessItems = [
    { key: "trip", label: "Reservation confirmed" },
    { key: "pre-arrival", label: "Arrival details" },
    { key: "documents", label: "Adventure waiver" },
    { key: "payments", label: "Payment settled" },
  ];

  return (
    <>
      <div className={styles.pageHeader}>
        <div>
          <p className={styles.eyebrow}>Your journey</p>
          <h1 className={styles.pageTitle}>A clear view of your stay</h1>
          <p className={styles.pageDescription}>
            Your itinerary stays flexible around weather and trail conditions. We will always confirm tomorrow&apos;s plan over dinner.
          </p>
        </div>
        <Link href={`/guest/${token}/pre-arrival`} className={styles.actionLink}>
          Finish preparing <ArrowRight aria-hidden="true" size={15} />
        </Link>
      </div>

      <div className={styles.overviewGrid}>
        <section className={styles.card} aria-labelledby="itinerary-heading">
          <div className={styles.cardHeader}>
            <div>
              <h2 id="itinerary-heading" className={styles.cardTitle}>Your itinerary</h2>
              <p className={styles.cardDescription}>Six thoughtful days, adapted to you and the mountain.</p>
            </div>
            <span className={styles.pill}><Sparkles aria-hidden="true" size={13} /> Host-curated</span>
          </div>
          <div className={styles.timeline}>
            {guestReservation.itinerary.map((item) => (
              <article className={styles.timelineItem} key={`${item.day}-${item.title}`}>
                <time className={styles.timelineDay}>{item.day}</time>
                <span className={styles.timelineMarker} aria-hidden="true" />
                <div>
                  <h3 className={styles.timelineTitle}>{item.title}</h3>
                  <p className={styles.timelineMeta}>{item.time} · {item.type}</p>
                  <p className={styles.timelineDetail}>{item.detail}</p>
                </div>
              </article>
            ))}
          </div>
        </section>

        <aside className={styles.sidebarStack} aria-label="Stay readiness and details">
          <section className={`${styles.card} ${styles.cardPadding} ${styles.readinessCard}`} aria-labelledby="readiness-heading">
            <div className={styles.readinessTop}>
              <div>
                <p className={styles.eyebrow}>Arrival readiness</p>
                <h2 id="readiness-heading" className={styles.readinessValue}>{completionPercent}%</h2>
                <p className={styles.readinessLabel}>{completedCount} of 6 trip steps complete</p>
              </div>
              <ShieldCheck aria-hidden="true" size={31} color="#b5d5be" />
            </div>
            <div className={styles.progressTrack} aria-label={`${completionPercent}% complete`}>
              <div className={styles.progressValue} style={{ width: `${completionPercent}%` }} />
            </div>
            <div className={styles.readinessList}>
              {readinessItems.map((item) => {
                const done = completion[item.key];
                const Icon = done ? CheckCircle2 : Circle;
                return (
                  <span className={styles.readinessItem} key={item.key}>
                    <Icon
                      aria-hidden="true"
                      size={15}
                      className={done ? styles.readinessIconDone : styles.readinessIconTodo}
                    />
                    {item.label}
                  </span>
                );
              })}
            </div>
          </section>

          <section className={`${styles.card} ${styles.cardPadding}`} aria-labelledby="stay-details-heading">
            <div className={styles.cardHeader} style={{ padding: 0, paddingBottom: "0.8rem" }}>
              <div>
                <h2 id="stay-details-heading" className={styles.cardTitle}>Stay details</h2>
                <p className={styles.cardDescription}>The essentials, all in one place.</p>
              </div>
              <span className={styles.iconBox}><Compass aria-hidden="true" size={18} /></span>
            </div>
            <dl className={styles.detailList}>
              <div className={styles.detailRow}>
                <dt className={styles.detailLabel}><MapPin aria-hidden="true" size={13} /> Property</dt>
                <dd className={styles.detailValue}>{guestReservation.property}</dd>
              </div>
              <div className={styles.detailRow}>
                <dt className={styles.detailLabel}><BedDouble aria-hidden="true" size={13} /> Suite</dt>
                <dd className={styles.detailValue}>{guestReservation.room}</dd>
              </div>
              <div className={styles.detailRow}>
                <dt className={styles.detailLabel}><Users aria-hidden="true" size={13} /> Party</dt>
                <dd className={styles.detailValue}>{guestReservation.guests} guests</dd>
              </div>
              <div className={styles.detailRow}>
                <dt className={styles.detailLabel}><Phone aria-hidden="true" size={13} /> Your host</dt>
                <dd className={styles.detailValue}>{guestReservation.host}</dd>
              </div>
            </dl>
          </section>
        </aside>
      </div>
    </>
  );
}
