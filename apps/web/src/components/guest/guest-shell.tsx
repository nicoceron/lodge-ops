"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { LockKeyhole, MessageCircleMore } from "lucide-react";
import { guestNavigation, guestReservation } from "@/data/guest-demo";
import { useGuestPortal } from "@/components/guest/guest-state";
import styles from "@/components/guest/guest-portal.module.css";

export function GuestShell({ token, children }: { token: string; children: React.ReactNode }) {
  const pathname = usePathname();
  const { completion } = useGuestPortal();
  const basePath = `/guest/${token}`;

  return (
    <div className={styles.portal}>
      <header className={styles.masthead}>
        <div className={styles.mastheadInner}>
          <div className={styles.brandRow}>
            <Link href={basePath} className={styles.brand} aria-label="Estancia Viento Sur guest portal">
              <span className={styles.brandMark} aria-hidden="true">V</span>
              <span>
                <span className={styles.brandName}>{guestReservation.property}</span>
                <span className={styles.brandPlace}>{guestReservation.location}</span>
              </span>
            </Link>
            <span className={styles.secureBadge} aria-label="Private reservation link">
              <LockKeyhole aria-hidden="true" size={13} />
              <span>Private reservation link</span>
            </span>
          </div>

          <div className={styles.welcomeGrid}>
            <div>
              <p className={styles.eyebrow}>Your stay · {guestReservation.reservationCode}</p>
              <p className={styles.welcomeTitle}>Patagonia is waiting, {guestReservation.guestName.split(" ")[0]}.</p>
              <p className={styles.welcomeCopy}>
                Everything for your stay lives here—from arrival details to your daily itinerary.
                Your host keeps this plan current as the adventure takes shape.
              </p>
            </div>
            <div className={styles.tripSummary} aria-label="Stay summary">
              <span className={styles.tripSummaryItem}>
                <span className={styles.tripSummaryLabel}>Dates</span>
                <span className={styles.tripSummaryValue}>{guestReservation.stay}</span>
              </span>
              <span className={styles.tripSummaryItem}>
                <span className={styles.tripSummaryLabel}>Stay</span>
                <span className={styles.tripSummaryValue}>{guestReservation.nights} nights</span>
              </span>
              <span className={styles.tripSummaryItem}>
                <span className={styles.tripSummaryLabel}>Party</span>
                <span className={styles.tripSummaryValue}>{guestReservation.guests} guests</span>
              </span>
            </div>
          </div>
        </div>
      </header>

      <nav className={styles.nav} aria-label="Guest portal navigation">
        <div className={styles.navInner}>
          {guestNavigation.map((item) => {
            const href = item.segment ? `${basePath}/${item.segment}` : basePath;
            const active = pathname === href;
            return (
              <Link
                key={item.step}
                href={href}
                aria-current={active ? "page" : undefined}
                className={`${styles.navLink} ${active ? styles.navLinkActive : ""}`}
              >
                <span
                  aria-hidden="true"
                  className={`${styles.stepDot} ${completion[item.step] ? styles.stepDotComplete : ""}`}
                />
                <span className={styles.navLongLabel}>{item.label}</span>
                <span className="sm:hidden">{item.shortLabel}</span>
              </Link>
            );
          })}
        </div>
      </nav>

      <main className={styles.main}>{children}</main>

      <footer className={styles.footer}>
        <div className={styles.footerInner}>
          <span>
            This portal opens only through your private magic link. We never ask you to look up a booking by surname.
          </span>
          <a className={styles.footerLink} href={`tel:${guestReservation.hostPhone.replace(/\s/g, "")}`}>
            <MessageCircleMore aria-hidden="true" size={15} /> Contact {guestReservation.host}
          </a>
        </div>
      </footer>
    </div>
  );
}
