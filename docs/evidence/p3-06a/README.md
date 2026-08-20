# P3-06A WP-11 evidence — 2026-08-19

Release classification: **the release owner accepts the Colombia/MCO + COP test-mode journey for the current P3-06A merge. Argentina/MLA + ARS is a deferred regional certification, not a merge blocker. Production-origin signed public-HTTPS delivery remains a production-activation/final-certification gate.**

No access token, test-user credential, public-link token, webhook key, signing secret, signature, or full provider identifier is stored in this directory.

## Provider journey

- Checkout Pro approved a real COP 10,000 test payment, redacted payment ID `…7197`.
- A dashboard/test notification signed with Mercado Pago's documented HMAC manifest was submitted through the temporary public HTTPS endpoint, accepted, and queued; the ordinary worker then performed the authoritative payment lookup.
- Replaying the same signed delivery and refreshing the browser return three times retained exactly one accounting effect.
- Mercado Pago reported COP 10,000 gross, COP 1,344 provider fee, COP 41.40 ICA withholding, COP 150 withholding tax, and COP 8,464.60 net.
- Mercado Pago's refund API was rejected by account policy. A COP 2,000 partial refund was therefore completed in the seller UI and then reconciled through authoritative lookup, redacted refund ID `…6852`.
- No Mercado Pago-originated HTTP delivery was observed. The dashboard/test signature-and-lookup proof used the documented HMAC format against the real provider payment and must not be presented as production-origin delivery evidence.

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

## Deferred regional and production-activation gates

- Mercado Pago credentials are application/integration scoped; OAuth seller authorization is required when connecting a different merchant. Site/country, currency and enabled payment methods are connection capabilities, not properties that may be inferred from a token. See Mercado Pago's official [credentials](https://www.mercadopago.com.ar/developers/en/docs/your-integrations/credentials) and [OAuth authorization-code](https://www.mercadopago.com.ar/developers/en/docs/security/oauth/creation) documentation.
- The Colombia hosted flow rendered the `CONT` and `OTHE` manual test cards as `UNDEFINED SOURCE` and kept the final action disabled. Direct payment and refund API attempts returned `PA_UNAUTHORIZED_RESULT_FROM_POLICIES`. These are preserved limitations of the authorized MCO connection, not current merge blockers.
- The authorized MCO developer context created redacted MLA test seller/buyer identities, but Mercado Pago returned no MLA seller access token. An ARS preference created with the MCO token remained on the Colombia host. No test-user credential was retained, and none of this is claimed as an MLA merchant journey.
- Argentina/MLA + ARS remains a future regional certification. It requires its own authorized seller application/account connection and the same approved/pending/rejected, refund/recovery, receipt and Finance/report invariants; the existing ARS and USD→ARS software contracts remain in scope for that future certification.
- Dashboard/test signed notification plus authenticated lookup is the documented test-mode notification proof exercised here. Production activation/final certification still requires observation of a Mercado Pago-originated, correctly signed delivery through the final public HTTPS endpoint and exactly-once ordinary-worker processing.
- With the release-owner decision, the deferred MLA/ARS certification no longer keeps P3-06A from merge. This evidence does not claim Argentina sandbox completion or production readiness.
