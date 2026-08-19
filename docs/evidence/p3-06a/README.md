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
- Because pending, rejected, and a provider-originated webhook delivery remain unproven under the required Argentina setup, the PR must remain draft and P3-06B must not start.
