# P3-06A WP-11 evidence — 2026-08-19

Release classification: **Colombia/MCO provider evidence recorded; Argentina/ARS WP-11 remains open.**

No access token, test-user credential, public-link token, webhook key, signing secret, signature, or full provider identifier is stored in this directory.

## Provider journey

- Checkout Pro approved a real COP 10,000 test payment, redacted payment ID `…7197`.
- A correctly signed public HTTPS delivery was accepted and queued; the worker then performed the authoritative payment lookup.
- Replaying the same signed delivery and refreshing the browser return three times retained exactly one accounting effect.
- Mercado Pago reported COP 10,000 gross, COP 1,344 provider fee, COP 41.40 ICA withholding, COP 150 withholding tax, and COP 8,464.60 net.
- Mercado Pago's refund API was rejected by account policy. A COP 2,000 partial refund was therefore completed in the seller UI and then reconciled through authoritative lookup, redacted refund ID `…6852`.
- No provider-originated webhook delivery was observed. The signature/lookup proof used the documented Mercado Pago HMAC format against the real provider payment.

## Database assertions

After duplicate delivery, return refreshes, and the partial refund:

- payment request state: `paid`;
- provider attempt state: `approved`;
- provider-origin payments: `1`;
- paid deposits: `1`;
- payment folio lines: `1` at COP -10,000;
- completed provider refunds: `1` at COP 2,000;
- refund folio lines: `1` at COP 2,000;
- final folio balance: COP 0;
- settlement gross/fee/net: COP 10,000 / COP 1,344 / COP 8,464.60;
- settlement reconciliation state: `variance`, because provider tax withholdings are separate from the modeled provider fee.

## Software-controlled closure proof

- The ordinary Compose worker consumes `provider-events`; the state-changing browser run sent a signed HTTP webhook and waited for that worker to apply exactly one payment/deposit/folio effect.
- Request expiry and stuck-refund recovery are scheduled with named overlap protection and `onOneServer()`; boundary, lock-expiry and crash/retry behavior is covered.
- Provider refund execution/recovery is idempotent and final provider-refund success plus Inn accounting commit under one locked transaction. A succeeded recovery replay does not call the provider again.
- Chargebacks use authoritative lookup, immutable full-fact revisions, account/environment/resource matching, and remaining-reversible-amount protection. `claim` topics remain visible as unsupported instead of being sent to the chargebacks API.
- Account Money and Released Money synthetic CSV fixtures exercise exact decimals, official fields, refunds, chargebacks, taxes/withholding, release movements, account-level payout isolation, duplicate/changed report revisions and mismatch visibility. Report rows persist only an explicit financial/correlation allow-list; a synthetic `PAYER_NAME` column is proven absent from stored canonical data.
- Payment-resource fields remain lookup facts: unavailable payout/settlement identity, date and status remain null until an authoritative report supplies them.
- Provider payment application atomically requests exactly one immutable payment receipt; replay repairs a missing request without duplicating it.

Latest gates before PR publication:

- SQLite: `325` tests, `310` passed, `15` expected PostgreSQL-only skips, `2,396` assertions.
- PostgreSQL: `325` tests, `324` passed, `1` expected Docker host-path skip, `2,433` assertions.
- Pint clean; PHPStan `0` errors; ESLint, TypeScript and web production build clean.
- Explicit provider Playwright: `1/1`, covering the signed ordinary-worker flow, Finance settlement investigation, automatic payment receipt PDF, partial refund authoritative recovery and refund receipt PDF.
- Authenticated Playwright: `7/7`, with the separately gated provider spec skipped in the ordinary run; public Playwright: `4/4`. The authenticated suite is serialized because its state-changing files share one Compose database and restart/clear shared application state.
- OpenAPI: `93` paths, `128` operations, `102` resolved references. API/web production builds and Docker health/smoke passed; Composer and both npm audits reported no advisories/vulnerabilities.

The final secret-scan result is reported in PR #8 after the post-documentation scan; this evidence file does not pre-claim it.

## Receipt evidence

The authenticated payment and refund download endpoints returned `200 application/pdf`. The downloaded bytes matched the immutable generated-document checksums. Both artifacts are one-page A4 PDFs and were visually inspected after rendering.

- payment receipt local-run SHA-256: `c6cd17bc8501e967d9fa0f90533d7234e544ae03fc95e40b5062abdb692b6eec`;
- refund receipt local-run SHA-256: `3bb6703507e9f96508f94f5c6ce0f18d1071148f4805f19cd4620fa50cb2242c`.

The PDFs are intentionally not committed because their immutable receipt bodies contain full provider references.

## Mobile evidence

[`wp11-approved-mobile-390x844@2x.png`](wp11-approved-mobile-390x844@2x.png) is the approved return rendered with a requested 390×844 CSS viewport and captured at device scale factor 2 (780×1688 pixels). It contains no provider ID or credential.

## Open provider blockers

- The runbook requires an Argentina seller/test buyer and ARS; this account is Colombia/MCO and COP.
- Mercado Pago's hosted Colombia flow rendered the `CONT` and `OTHE` manual test cards as `UNDEFINED SOURCE` and disabled the final action.
- Direct payment and refund API attempts returned `PA_UNAUTHORIZED_RESULT_FROM_POLICIES`.
- The authorized MCO developer context created same-country MLA test seller/buyer identities, retained only as redacted hashes, but Mercado Pago returned no MLA seller access token. An ARS preference created with the MCO token remained on the Colombia host. No test-user credential was retained.
- Dashboard/test signed notification plus authenticated lookup is the documented sandbox notification path and is proven deterministically. Production activation still requires a real provider-signed delivery over public HTTPS.
- Because approved/pending/rejected and refund execution/recovery remain unproven with a same-country MLA seller application/access token, the PR must remain draft and P3-06B must not start.
