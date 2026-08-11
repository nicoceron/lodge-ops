import type { Metadata } from "next";

export const metadata: Metadata = { title: "Security", description: "How LodgeOps isolates tenants, protects guest access, and keeps authenticated workflows in Laravel and Filament." };

export default function SecurityPage() {
  return <>
    <section className="page-hero"><div className="shell"><div className="eyebrow">Security architecture</div><h1>One authenticated boundary. Explicit tenant ownership.</h1><p className="lede">The public website contains no staff session, tenant data, API proxy, or protected account pages. Authentication and the entire staff application stay inside Laravel and Filament.</p></div></section>
    <section className="section"><div className="shell detail-grid">
      <article className="detail-card"><h3>Tenant isolation</h3><p>Every tenant-owned record carries a tenant ID. Active membership, model scopes, policies, service checks, and database relationships enforce ownership independently.</p></article>
      <article className="detail-card"><h3>Laravel authentication</h3><p>Filament uses encrypted Laravel sessions, CSRF protection, password recovery, account security, and recoverable application-based MFA at the staff application boundary.</p></article>
      <article className="detail-card"><h3>Guest access</h3><p>Opaque magic links are hashed at rest and exchanged once. The resulting token stays in the encrypted server-side session and is never rendered into HTML or browser local storage.</p></article>
      <article className="detail-card"><h3>Operational privacy</h3><p>Role projections deliberately redact guest identity or dietary details when a role does not need them. Property-scoped memberships narrow the active workspace further.</p></article>
      <article className="detail-card"><h3>Background work</h3><p>Queued jobs restore an explicit tenant context. Outbox delivery, retries, audit events, and idempotency controls prevent cross-tenant or duplicate external work.</p></article>
      <article className="detail-card"><h3>Production responsibility</h3><p>Production rollout still requires managed secrets, TLS, private object storage, backup restoration drills, monitoring, external security review, and jurisdiction-specific validation.</p></article>
    </div></section>
  </>;
}
