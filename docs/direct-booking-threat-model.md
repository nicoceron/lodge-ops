# Direct-booking threat model

Reviewed: 2026-08-20
Boundary: unauthenticated published content/search, opaque booking session, hosted checkout/manual evidence handoff, state events and scheduled maintenance

## Assets and trust boundaries

Protected assets are inventory capacity, server-priced commercial snapshots, reservation/confirmation state, payment truth, guest contact/consent, voucher value, provider credentials/events and operational privacy. The public browser, marketing site, CDN/WAF, bot widget, hosted-checkout browser return and uploaded evidence are untrusted. Laravel services plus PostgreSQL constraints/locks are the authority boundary. Mercado Pago is authoritative only through the existing signed delivery and server-to-server lookup contract. Finance review is authoritative only for the manual-evidence branch.

## Threats and frozen controls

| Threat | Material outcome | Prevent/detect/recover controls | Contract evidence |
| --- | --- | --- | --- |
| Scraping / inventory enumeration | Competitor learns occupancy; traffic cost | Public opaque keys, aggregate boolean only, no exact counts/resource names, IP+property throttles, cache published content only | Safe projection tests and fixtures deny IDs/counts |
| Voucher brute force | Unauthorized discount / oracle | Voucher input is write-only, normalized server-side, per-IP/property/session limiter, identical invalid/ineligible public error, no analytics/log value | `commercial-voucher` plus direct mutation limits; generic errors |
| Bot hold denial | Inventory is exhausted by fake holds | Server-side Turnstile on begin/hold, launch-time secret/hostname readiness, single-use action/hostname checks, connection/timeout fail-closed result, per-session hold/hour limit, property/IP limits, commit-time lock, short bounded expiry | Turnstile primary-shape, configuration and transport-exception tests with stray HTTP blocked; hold schedule |
| Token theft/replay | IDOR and guest-data exposure | Separate 64-character session/recovery bearers, hash-only persistence, independent expiries, property binding, generic failure, row-locked credential rotation/revocation, no-store, no URL/query token, rate limits | Session expiry plus post-expiry recovery/rotation/replay/property tests |
| IDOR / tenant crossover | Other property or tenant accessed | Property slug + token binding, property-inclusive composite foreign keys for every public subject/payment/order link, global tenant scope after resolution, generic 404, no internal UUIDs | Cross-tenant and same-tenant cross-property negative tests on SQLite/PostgreSQL |
| Price/currency tampering | Underpayment or wrong ledger currency | Request schemas omit authoritative amounts/conversion; server quote checksum; published commercial versions; integer money/currency | Request-schema gate and existing quote service |
| Webhook forgery / return spoofing | False paid/confirmed state | Browser return never transitions; signed webhook intake plus authoritative lookup only; provider event replay/idempotency | Provider contract remains unchanged; state authority rejects browser |
| Late payment / inventory loss | Paid guest lacks capacity | Expired hosted settlement becomes `paid_needs_review`; accepted manual evidence whose hold lapses is retained in `finance_review`; Finance recovers valid inventory or provider-refunds; neither path restores inventory or auto-confirms | Late-provider and late-manual review/refund transition tests |
| Duplicate/competing checkout | Double charge/confirm | One app-owned active payment request, stable idempotency, supersede before retry, row/version lock, subsequent paid attempt to review | State/retry contract; Agent 07 integration obligation |
| Manual evidence fraud | False bank payment | Private validated/scanned upload; `evidence_pending` then scanner then Finance; upload alone cannot confirm | Separate manual branch authority test |
| PII/log leakage | Privacy breach | Encrypted contact, hidden casts, token hash, keyed prefix hash, attribution/event allowlists, no-store, generic errors, scrub schedule; forbidden logging list below | Projection/fixture and cleanup tests |
| Resource exhaustion | Availability/quote/provider/database denial | Multi-axis atomic limiter, payload/length bounds, short provider timeout, cache only publications, scheduler batching | Rate-limit test/config and Turnstile timeout |
| Stale/replayed mutation | Wrong lifecycle or duplicate side effect | Required UUID idempotency key, canonical checksum, exact replay, different-body conflict, expected state version, DB unique retry identity; Turnstile receives the same deterministic UUID | Replay/different-body/version and PostgreSQL race tests plus exact Turnstile request-shape tests |
| Undefined policy/copy | Guest accepts invented or cross-kind text | Only immutable exact-kind published source may render; category/program association is model/database guarded and projection-filtered; missing locale/version is generic 404 and readiness blocker | Dual-kind projection, publication constraint and readiness tests |
| Private-media exposure | Signed URL/path leaks | Public projection accepts only opaque `public-media://` or query-free HTTPS references and alt text; private objects stay behind existing authorization | Media model and fixture scan |

## Progressive abuse controls and accessibility

Limits are keyed independently by IP, public property and hashed session; holds add a per-session and per-IP hourly limit. Repeated failures should progress at the edge from normal limit to Turnstile challenge to a temporary deny, without changing API errors into an account/email oracle. Edge configuration is Agent 13 runtime work; application limits are already frozen.

Turnstile validation is server-side. Tokens are treated as short-lived, single-use credentials and are submitted with the expected action, a nonempty hostname allowlist, remote IP when available and the stable UUID command idempotency key. A property requiring bot verification cannot pass launch readiness without a usable server secret and hostname allowlist. The response must have success, exact action, allowlisted hostname and a fresh `challenge_ts`. Malformed UUID, connection/timeout exception, non-success response, missing secret or missing allowlist returns a documented safe failure and never grants access; malformed/local configuration never sends HTTP. Tests use the primary protocol response shape (`success`, `challenge_ts`, `hostname`, `action`, `cdata`, `error-codes`) and prevent stray HTTP. See [Cloudflare server-side validation](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/).

The challenge cannot be the only path for a person with a disability. Each enabled property must publish an accessible fallback URL before readiness passes. The fallback is a human booking/contact workflow and does not bypass inventory/payment controls or silently create a hold. Agent 08 must provide labelled error summaries, focus management, status announcements, keyboard operation, sufficient target sizes and timeout warning/recovery consistent with [WCAG 2.2](https://www.w3.org/TR/WCAG22/).

## Privacy and logging denylist

Never place the following in source, logs, exceptions, queues, outbox payloads, analytics, exports, screenshots or evidence: raw direct-booking session/recovery/guest/payment tokens, their hashes, Turnstile token, voucher code, email, phone, raw IP, bank evidence/content, card PAN/CVV/expiry/track/PIN, provider access/webhook secret, provider payload or signed private-media URL. Safe events contain only opaque order reference, event name, state/version, UTC time, correlation ID and an allowlisted reason code. The dedicated PII-scrub event preserves business timestamps and applies even to revoked orders; abandoned provisional Guest cleanup is explicit and never deletes a shared guest.

## Protocol and framework decisions

- Laravel request validation remains server authority; failed JSON validation is 422 and must not echo secret inputs. See [Laravel validation](https://laravel.com/docs/13.x/validation) and [HTTP tests](https://laravel.com/docs/13.x/http-tests).
- Laravel atomic rate-limit increments/keys back multi-axis controls. See [Laravel rate limiting](https://laravel.com/docs/13.x/rate-limiting).
- Signed URLs can protect immutable public links, but the booking session uses an opaque revocable bearer because it needs rotation and stateful expiry; ignored signed-URL parameters would remain attacker-controlled. See [Laravel signed URLs](https://laravel.com/docs/13.x/urls#signed-urls).
- The design addresses OWASP API risks for object/property authorization, resource consumption, sensitive business flows, SSRF/external trust and inventory management. See [OWASP API Security Top 10 2023](https://owasp.org/API-Security/editions/2023/en/0x11-t10/).
- The public schema is OpenAPI 3.1.2 and uses JSON Schema 2020-12 semantics. See the [OpenAPI Specification](https://spec.openapis.org/oas/v3.1.2.html).

## Residual and downstream obligations

Agent 07 must connect these controls to real availability/quote/commit/payment/evidence services, invoke the locked held-reservation payment-request seam, implement exact HTTP response replay in the public tenant context, enforce one active competing checkout, emit safe events after commit and add real PostgreSQL provider/inventory races. Agent 08 must use only the public DTOs and exercise every pending/failure/recovery state. Agent 09 must prove the complete browser journey, edge/WAF behavior, real hosted payment/manual review, late payment, cleanup and accessibility. Until then, the foundation route is deliberately unavailable.
