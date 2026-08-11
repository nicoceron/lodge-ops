import type { Metadata } from "next";
import Link from "next/link";
import type { ReactNode } from "react";
import "./globals.css";

export const metadata: Metadata = {
  title: {
    default: "LodgeOps — Run the whole lodge from one place",
    template: "%s · LodgeOps",
  },
  description:
    "A modern operating system for independent lodges and outfitters: reservations, resources, guests, operations, payments, and reporting in one place.",
  metadataBase: new URL(process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000"),
  openGraph: {
    title: "LodgeOps",
    description: "One operating system for reservations, guests, resources, and lodge operations.",
    type: "website",
  },
};

const manageUrl = process.env.NEXT_PUBLIC_MANAGE_URL ?? "http://localhost:8000/manage";

export default function RootLayout({ children }: Readonly<{ children: ReactNode }>) {
  return (
    <html lang="en" data-scroll-behavior="smooth">
      <body>
        <header className="site-header">
          <div className="shell nav-wrap">
            <Link className="brand" href="/" aria-label="LodgeOps home">
              <span className="brand-mark" aria-hidden="true">L</span>
              <span>LodgeOps</span>
            </Link>
            <nav className="main-nav" aria-label="Main navigation">
              <Link href="/features">Features</Link>
              <Link href="/pricing">Pricing</Link>
              <Link href="/security">Security</Link>
            </nav>
            <div className="nav-actions">
              <a className="text-link" href={`${manageUrl}/login`}>Sign in</a>
              <a className="button button-small" href={manageUrl}>Open LodgeOps</a>
            </div>
          </div>
        </header>
        <main>{children}</main>
        <footer className="site-footer">
          <div className="shell footer-grid">
            <div>
              <Link className="brand footer-brand" href="/">
                <span className="brand-mark" aria-hidden="true">L</span>
                <span>LodgeOps</span>
              </Link>
              <p>Calm operations for remarkable stays.</p>
            </div>
            <div>
              <strong>Product</strong>
              <Link href="/features">Features</Link>
              <Link href="/pricing">Pricing</Link>
              <Link href="/security">Security</Link>
            </div>
            <div>
              <strong>Application</strong>
              <a href={`${manageUrl}/login`}>Staff sign in</a>
              <a href={manageUrl}>Manage your lodge</a>
            </div>
          </div>
          <div className="shell footer-bottom">© {new Date().getFullYear()} LodgeOps. Built for independent hospitality.</div>
        </footer>
      </body>
    </html>
  );
}
