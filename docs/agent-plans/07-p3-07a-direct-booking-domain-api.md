# Agent 07 — P3-07A direct-booking domain and API

## Copy/paste assignment

> Implement the frozen direct-booking contract after Agents 04 and 06 merge. Read this file, the coordinator README, both prerequisite diffs/evidence, all booking/quote/hold/payment/confirmation services and P3-06A invariants. Build public-safe availability/quote/session APIs and atomic hold/deposit/payment orchestration using existing domain commands. Only authoritative provider reconciliation may confirm a paid booking. Late success must never overbook. Do not build a second pricing or inventory engine and do not trust the return URL.

## Branch and ownership

- Branch: `codex/p3-07a-direct-booking-api` from synchronized `main` after Agents 04 and 06.
- Own application services/controllers/requests/resources and **API routes in `apps/api/routes/api.php`** for the frozen public contract, booking-order orchestration, hold/payment/confirmation integration, bot verification, expiry/cleanup commands/jobs, API tests/OpenAPI/runbook. Agent 08 owns browser page routes in `routes/web.php` and the Next CTA.
- Preserve contract compatibility for Agent 08. Propose any required change through a versioned schema/fixture before implementation.
- Do not own visual UX, provider gateway internals, communications delivery, or production infrastructure.

## Authoritative orchestration

- Public search calls existing `AvailabilityQuery/Service`; quote calls the authoritative `BookingQuoteService` and commercial rules. Return public category/program data only.
- Begin booking atomically validates bot proof, consent version, quote expiry/checksum and guest input; commits the inventory hold; provisions a deposit obligation valid while held; issues a payment request; and advances the direct-booking order with an idempotent response.
- Fix the current domain gap where a held reservation cannot obtain the required deposit/payment request. Use an explicit direct-booking provisioning service/state, not premature reservation confirmation.
- Hosted checkout creation occurs after the local hold/request commit. Remote timeout recovers by request/idempotency identity and authoritative provider fetch before creating another checkout.
- Authoritative provider success uses the established financial lock order and exactly once: records payment, satisfies deposit, rechecks valid hold/inventory, confirms reservation, provisions tasks/occurrences/documents/communications, and finalizes the booking order.
- Provider return URL only displays/polls current Inn state. It never records payment or confirms.
- If approval is late, hold expired, resource lost, amount/currency/account mismatched, or reservation cannot confirm: preserve provider/payment truth in `paid_needs_review`, block allocation, create Finance refund/reconciliation work, and show a non-misleading guest status.
- Active provider checkout may extend the hold only to a configured provider-expiry plus bounded grace. Record every extension and never make it indefinite.
- Manual bank transfer uses the frozen awaiting-evidence/Finance-review states, private evidence workflow and exact-once approval/payment application. Evidence upload never confirms by itself; rejection/correction and hold-expiry policy are explicit.
- Implement/schedule idempotent commands for expiring direct-booking sessions, stale checkouts/holds and retention cleanup with named locks, `onOneServer()`, property-local/UTC audit and crash/replay tests.
- Enforce the property launch-readiness evaluator on every public entry/begin action and provide authorized diagnostics for missing configuration.

## Security, privacy, and resilience

- IP/property/session rate limits plus Turnstile server call with hostname/action verification, timeout/fail policy and idempotency.
- Prevent guest/email enumeration with opaque order token and generic status/errors.
- Encrypt/minimize PII; redact logs, job payloads, analytics and error responses. Validate locale, phone/email, occupancy and Unicode controls.
- Consent to terms/privacy/cancellation and optional marketing are separate immutable facts. A later terms version does not rewrite prior consent.
- Queue after commit; explicit queue/backoff/timeout/retryUntil/failure. No external HTTP while holding inventory DB locks.
- Property disabled, sale closed, rule changed, provider outage, queue outage and communication outage degrade to safe recoverable states.

## Tests and API acceptance

- Search/quote parity with staff path for long stay, same-day, buyout, group/program, included/optional services, voucher and multi-currency rounding.
- Two anonymous guests compete for last unit; only one hold/confirmation wins.
- Quote/hold/session/checkout expiry before/at/after; bounded extension; cancellation and inventory block races.
- Mutation replay and same key/different body; duplicate checkout; timeout after remote success; provider return before webhook; duplicate/reordered webhook; concurrent reconciliation workers.
- Approved/pending/rejected/canceled/refunded/chargeback; late approval; amount/currency/account/reference mismatch; refund failure/recovery.
- Cross-property token substitution, token replay/rotation, email enumeration, bot/rate isolation, malicious locale/HTML, log redaction.
- Atomic rollback proves no orphan reservation/hold/deposit/request/order on local failure.
- API state-changing test from anonymous search through confirmed reservation and exact accounting; PostgreSQL races for inventory/payment.
- Run universal gates, P3-06 regressions, contract fixtures and secret scan. Handoff includes migration/state diagrams and known provider gates.

## Primary references

- [Laravel database transactions](https://laravel.com/docs/13.x/database), [queues](https://laravel.com/docs/13.x/queues), [rate limiting](https://laravel.com/docs/13.x/rate-limiting), [HTTP client](https://laravel.com/docs/13.x/http-client), and [cache locks](https://laravel.com/docs/13.x/cache)
- [Cloudflare Turnstile validation](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/)
- [Mercado Pago Checkout Pro overview](https://www.mercadopago.com.ar/developers/en/docs/checkout-pro/overview)
