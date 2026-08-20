# Agent 06 — P3-07F direct-booking contract and threat model

## Copy/paste assignment

> Freeze the public direct-booking state machine and API contract after Agent 04 is merged. Threat-model prework may begin after P3-06A, but the named branch/schema/OpenAPI must start from post-Agent-04 `main`. Read this file, the coordinator README, Rincón Grande requirements, commercial/payment/guest-link code, current OpenAPI contract, Next/Laravel security docs and WCAG 2.2. Deliver an approved contract, persistence foundation, public content/policy model, idempotency/error semantics, threat model and executable contract tests. Do not build the full UI or duplicate availability/pricing/payment logic. Direct booking remains in scope unless the coordinator records written client deferment.

## Branch and ownership

- Branch: `codex/p3-07f-direct-booking-contract` from synchronized `main` after Agent 04. Pre-Agent-04 work is threat-model documentation only under the README exception.
- Own public property/bookable projection, direct-booking order/session persistence, state machine, consent/attribution snapshot, route/OpenAPI schemas, error/idempotency contract, threat model, feature flags and contract fixtures.
- Agents 07 and 08 consume this frozen contract. After merge, breaking changes require a versioned contract and coordinator approval.
- Read: `AvailabilityQuery/Service`, `BookingQuoteService`, `CommitBookingQuote`, `ReservationService`, payment request/link services, guest portal token pattern, idempotency middleware, throttles, public/web routes, OpenAPI and tests.

## State and persistence contract

- Public property slug and `direct_booking_enabled` state with versioned localized property/category/program copy, media references, alt text, publication state and safe projection. Define the public/private media boundary. Never expose internal IDs, room names, occupancy notes, housekeeping, staff or exact inventory counts.
- Add `direct_booking_orders`/sessions with tenant/property, hashed opaque token, quote/hold/reservation/payment-request references, lifecycle state/version, expiry, consent version/checksum/time/IP prefix policy, attribution, locale, safe failure code and timestamps.
- State machine at minimum: `started → quoted → held → payment_pending → paid_pending_confirmation → confirmed`; manual bank-transfer branch `held → awaiting_manual_payment → evidence_pending → finance_review → confirmed`; terminal/recovery states `expired`, `payment_failed`, `paid_needs_review`, `evidence_rejected`, `canceled`, `refunded`.
- Define transition authority and retry identity for every mutation. Browser return/query parameters never transition money or confirmation.
- Freeze bounded hold-extension policy while hosted checkout is active, late-payment policy, inventory-loss policy, expired-session recovery and competing-payment behavior.
- Define public payment capabilities per property/currency. Hosted Checkout Pro and manual bank-transfer instructions/evidence are supported only when enabled; manual approval invokes existing Finance evidence review and only then confirmation.
- Store raw guest/session tokens only at issue time; persist hashes. Rotation/revocation invalidates earlier tokens and all access is rate-limited.

## Public API contract

- Endpoints/projections for property, search availability, quote, begin/hold, booking status, payment checkout, retry/recover, confirmation and policy/consent documents.
- Add versioned publish/retire sources for terms, privacy, cancellation/no-show and marketing consent plus localized public content/media. A policy endpoint cannot synthesize undefined text.
- Search returns category/program aggregates and bookability, not resource IDs/counts exploitable for occupancy intelligence.
- Public request never accepts price, total, deposit, currency conversion, allocation, reservation state or provider status as authoritative.
- Standard errors: validation, unavailable, quote stale, hold expired, conflict, rate limited, bot rejected, payment pending/failed, paid-needs-review and generic not-found without email/account enumeration.
- Require `Idempotency-Key` on mutations; canonical request checksum and exact replay status/body. Same key/different body is a conflict.
- Publish safe caching/no-store policy, correlation ID, versioning, locale/currency semantics and property-local date format.

## Threat model and decisions

- Model scraping, inventory enumeration, voucher brute force, bot holds, token theft/replay, IDOR, price tampering, webhook forgery, return spoofing, late payment, duplicate checkout, PII/log leakage and denial of inventory.
- Specify per-IP/property/session throttles, Cloudflare Turnstile server validation, progressive abuse controls and accessible fallback. Client-only validation is insufficient.
- Define consent snapshot (terms/privacy/cancellation/marketing separately), retention, analytics attribution allowlist and no-PII event names.
- Define a property launch-readiness evaluator that fails closed unless public copy/media/alt text, active commercial rules, policies, supported currency/payment capability, provider/bank instructions and required templates are valid.
- Assign scheduled expiry/cleanup ownership: direct-booking session, stale checkout, bounded hold extension and retention cleanup use explicit commands/jobs with singleton scheduling and versioned outcomes.
- Choose same-origin Laravel server-rendered/Livewire public booking as the default fastest/reliable UI; `apps/web` links to it. A Next BFF is not introduced unless a reviewed ADR proves the need.

## Tests and completion

- Migration/constraints/state transition unit tests and OpenAPI examples for every state/error.
- Token issue/hash/rotate/revoke/expiry/replay and tenant/property isolation.
- Same key/same body replay; same key/different body; concurrent state version update.
- Safe projection snapshots prove no internal resource ID/count, secret, staff note or provider metadata escapes.
- Rate-limit and bot-verification contract tests use fakes with stray HTTP prevented.
- Contract-driven mock server/fixtures allow Agent 08 to implement every screen before Agent 07 is done.
- Run `make contract`, relevant API/PostgreSQL tests, PHPStan/Pint and `git diff --check`. No “booking works” claim belongs in this foundation PR.

## Primary references

- [Laravel rate limiting](https://laravel.com/docs/13.x/rate-limiting), [signed URLs](https://laravel.com/docs/13.x/urls), [validation](https://laravel.com/docs/13.x/validation), and [HTTP tests](https://laravel.com/docs/13.x/http-tests)
- [Cloudflare Turnstile server validation](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/)
- [OWASP API Security Top 10](https://owasp.org/API-Security/)
- [OpenAPI specification](https://spec.openapis.org/oas/) and [WCAG 2.2](https://www.w3.org/TR/WCAG22/)
