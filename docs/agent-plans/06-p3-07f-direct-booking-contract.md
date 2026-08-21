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

## Implementation record — 2026-08-20

Implemented on `codex/p3-07f-direct-booking-contract` from post-commercial/tender `main` (`028cde9d1c0235f654385e651121bdac7fa6035f`). The foundation owns only the frozen contract boundary:

- 12 versioned public routes and schemas, 15-state/authority machine, 13 safe errors, cache/header/locale/currency/property-local-date semantics and exact idempotency requirements;
- migrations `2026_08_20_060001` and forward-only hardening `060002` for property publication/readiness, safe public bookable/media sources, localized payment capabilities, independently expiring hashed session/recovery credentials, property-inclusive references, separate immutable consent snapshots and transition/maintenance events;
- bounded holds, late-payment/recovery/competing-payment decisions, token rotation/revocation, attribution/IP minimization, singleton expiry/retention maintenance and PostgreSQL concurrency tests;
- deterministic mock router plus screen/state/error fixtures, threat model and same-origin Laravel/Livewire ADR.

Independent review hardening freezes a dedicated locked payment-request issuance seam for held reservations; atomically aligned reservation/order/request hold extensions; late accepted-manual-evidence Finance/refund handling; same-tenant cross-property database negatives; schema-validated safe projections; exact kind/locale/effective/media/provider readiness; UUID-bound Turnstile verification; and a dedicated PII-scrub maintenance event that preserves confirmation facts and cleans or defers abandoned provisional Guest PII. Follow-up re-review hardening makes rotation/revocation share a lock and prevents stale resurrection, makes required Turnstile configuration a launch gate and transport exceptions safe, replaces partial state/error catalogs with full semantic envelopes and action parity, enforces exact publication/item kind at model/database/projection boundaries, and makes the `060002` rollback guard inspect every new live fact before DDL. Final hardening preserves PAN/SAD detection while safely recognizing underscore-prefixed UUID references, serializes retention cleanup against recovery and late-payment review with order-to-reservation-to-Guest locks, gives all 13 errors distinct schema-valid OpenAPI/mock envelopes, rejects item copy without an item at model and database boundaries, and documents the required Turnstile environment names.

The 12 route handlers intentionally return safe `503 booking_unavailable` responses. Agent 07 must connect existing availability, commercial, reservation, payment and evidence services and existing exact-response idempotency persistence before any booking-workflow claim. Agent 08 consumes DTOs/fixtures only and does not calculate inventory, money or state.

### Verified gates

- Direct contract: 12 paths, 15 full state envelopes, 13 errors, 25 fixtures; exact transition-authority, public-action and complete error-envelope parity checked.
- Aggregate route/OpenAPI parity after merging integration-kernel `main` (`7ddd9ca08144ead0fd53c0bbc51185c123d0837a`): 134 paths, 170 operations, 118 resolved references.
- SQLite focused: 18 passed / 168 assertions; 5 PostgreSQL-only tests skipped intentionally.
- PostgreSQL focused including state/idempotency, revoke/rotate and both maintenance-versus-recovery/review row-lock races: 23 passed / 197 assertions.
- Commercial, integration-kernel, payment, tender and reservation compatibility pass inside both complete engine suites.
- Full SQLite: 409 passed / 3,422 assertions; 34 PostgreSQL-only tests skipped intentionally.
- Full PostgreSQL: 443 tests / 3,635 assertions; one platform-specific skip.
- The full-suite investigation also fixed a nondeterministic false positive where audit snapshots containing nested JSON were scanned as one opaque string, allowing numeric checksum runs to resemble a PAN. The guard now recursively scans typed nested fields, still rejects PANs in nested guest content, and treats only exact ID/hash/checksum/generated-storage/UUID facts as non-card facts; underscore-prefixed UUID references are explicitly covered without weakening PAN/SAD rejection.
- Pint: 896 files pass. PHPStan: no errors. API build: pass after mounting the existing Composer vendor volume and an isolated Linux Node dependency volume into the Node build container.

Final full-suite, diff/secret scan, commit, PR and CI receipts belong in [`docs/evidence/p3-07f/README.md`](../evidence/p3-07f/README.md). This record does not claim a working public booking journey.
