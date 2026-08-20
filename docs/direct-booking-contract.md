# P3-07F direct-booking contract freeze

Frozen: 2026-08-20
Contract version: `2026-08-20` / public API `v1`
Consumers: Agent 07 domain/API and Agent 08 public UX

This document freezes the public protocol and state ownership. It is not a claim that a booking journey works in this foundation PR. The route handlers deliberately fail closed with `booking_unavailable` until Agent 07 connects the existing availability, commercial, reservation, guest-evidence and payment services.

## Authority boundary

- Inn's `AvailabilityQuery`/`AvailabilityService` remain inventory authority. Public search receives only category/program bookability, never resource IDs, room names, exact available counts, housekeeping state, staff notes or occupancy intelligence.
- `BookingQuoteService` remains pricing authority. Requests contain property-local dates, occupants and public selection keys; they cannot contain authoritative price, total, deposit, allocation, reservation state, provider state or currency conversion.
- `CommitBookingQuote` and `ReservationService` remain hold and confirmation authority. A rendered confirmation page is not confirmation authority.
- App-owned `PaymentRequest` remains the durable payment intent. A hosted URL is replaceable. Provider settlement is accepted only after the existing signed-event plus authoritative-lookup flow. Browser return/query fields never transition money or confirmation.
- Manual bank-transfer evidence enters the existing private upload/scanning and Finance review path. Uploading evidence is not payment approval.
- All money is an integer minor-unit amount plus an ISO 4217 currency. All audit timestamps are UTC. Arrival/departure are `YYYY-MM-DD` property-local business dates interpreted in the published IANA timezone.

## Public API

The canonical schemas, examples, headers and errors are in [`contracts/openapi.yaml`](../contracts/openapi.yaml) and [`contracts/direct-booking/v1/openapi.yaml`](../contracts/direct-booking/v1/openapi.yaml).

| Endpoint | Purpose | Cache | Authority |
| --- | --- | --- | --- |
| `GET /direct-booking/properties/{propertySlug}` | Safe property, media, bookables and payment capabilities | `public, max-age=60, stale-while-revalidate=300` | Published projection only |
| `GET .../policies/{policyKind}` | Exact localized terms/privacy/cancellation/no-show/marketing version | same public cache | Published source only; undefined is generic 404 |
| `POST .../availability` | Aggregate bookability | `no-store, private` | Existing availability services |
| `POST .../orders` | Issue a hashed-token session | no-store | Session service after server-side bot validation |
| `POST .../orders/{ref}/quote` | Attach a server-priced quote | no-store | Commercial service |
| `POST .../orders/{ref}/hold` | Commit quote and inventory hold with consent snapshots | no-store | Inventory/reservation services |
| `GET .../orders/{ref}` | Safe lifecycle/actions projection | no-store | Persisted state/event facts |
| `POST .../orders/{ref}/checkout` | Create hosted checkout or select manual transfer | no-store | Payment orchestrator/capability |
| `POST .../orders/{ref}/payments/retry` | Replace a failed active checkout safely | no-store | Payment orchestrator |
| `POST .../orders/{ref}/manual-payment-evidence` | Submit private evidence | no-store | Evidence service, not money authority |
| `POST .../orders/{ref}/recover` | Re-price/recheck expired session and rotate token | no-store | Recovery + commercial/inventory services |
| `GET .../orders/{ref}/confirmation` | Safe confirmed reservation summary | no-store | Reservation domain only |

Every mutation except the non-mutating availability search requires a canonical UUID `Idempotency-Key`. The canonical request checksum includes normalized JSON/body facts and the command identity. Same key/same body returns the exact stored status/body with `Idempotency-Replayed: true`; same key/different body returns `idempotency_conflict`. `expected_state_version` is an independent optimistic-concurrency guard. The same UUID is sent to Turnstile as its replay-safe verification identity; malformed values fail before any outbound verification request.

`X-Correlation-ID` is accepted only in the safe character/length contract and otherwise replaced. It is returned on every response and must never contain guest/provider data. Published endpoints return `Content-Language`; unsupported locale/currency fails explicitly and never synthesizes policy text or conversion.

## State and transition authority

Every accepted transition writes one immutable `direct_booking_order_events` fact with state version, retry identity, canonical checksum, authority, UTC occurrence and allowlisted non-PII facts. Internal/outbox event names are `direct_booking.order.<state>` using the safe event schema in the contract.

| From | To | Sole authority |
| --- | --- | --- |
| `started` | `quoted` | pricing service |
| `quoted` | `held` | inventory service after commit-time lock/check |
| `held` | `payment_pending` / `awaiting_manual_payment` | payment orchestrator after capability check |
| `payment_pending` | `paid_pending_confirmation` / `payment_failed` / `paid_needs_review` | authoritative provider lookup |
| `awaiting_manual_payment` | `evidence_pending` | guest evidence service after private upload acceptance |
| `evidence_pending` | `finance_review` / `evidence_rejected` | evidence scanner; the scheduler may escalate already-accepted evidence to Finance when its inventory hold expires |
| `finance_review` | `confirmed` / `evidence_rejected` / `refunded` | Finance review or refund service; confirmation invokes reservation service |
| `paid_pending_confirmation` | `confirmed` / `paid_needs_review` | reservation service after inventory/confirmation checks |
| `payment_failed` | `payment_pending` | payment orchestrator retry |
| `evidence_rejected` | `awaiting_manual_payment` | payment orchestrator retry |
| eligible live states | `expired` | singleton scheduler |
| `expired` | `started` | recovery service with token rotation/re-price/recheck |
| `expired` | `paid_needs_review` | authoritative late provider payment |
| `paid_needs_review` | `confirmed` / `refunded` | Finance / refund service |
| `confirmed` | `canceled` / `refunded` | cancellation / refund service |
| `canceled` | `refunded` | refund service |

The full machine, including maintenance self-events, is executable in `DirectBookingStateMachine` and machine-readable under `x-state-machine`.

## Hold, late payment and competing payment policy

- The default initial hold is 30 minutes. Hosted checkout may extend it once by at most 15 minutes; the absolute limit is 45 minutes from `held_at`. The extension is a versioned event, never a browser timer.
- Quoted, reservation-hold and checkout expiries are distinct persisted facts. A held order must reference the same-property authoritative quote and held reservation, and its frozen hold expiry must equal `reservations.hold_expires_at`.
- `IssueDirectBookingPaymentRequest` is the only foundation seam allowed to issue a request for a held direct-booking reservation. Under one row-lock transaction it verifies the immutable quote checksum and deposit policy, creates or validates the quoted deposit schedule, freezes integer amount/currency and authoritative hold expiry in the payment-request snapshot, sets the request expiry to that hold, links it to the order and enters `payment_pending`. The general staff issuance rule remains confirmed/checked-in only.
- Checkout creation does not extend a hold automatically. A single bounded extension locks and updates the order, held reservation and linked payment request atomically; failures leave all three expiries unchanged.
- A provider payment found after expiry or after inventory loss becomes `paid_needs_review`. Inn does not silently recreate inventory or auto-confirm it. Finance must confirm a valid recovered reservation or refund through the existing provider path.
- Only one active app-owned payment request/checkout may compete. Retrying revokes/supersedes the prior open request before replacement. Once one attempt is authoritatively paid, later paid attempts are `paid_needs_review` for reconciliation/refund; they do not double-confirm.
- Manual evidence and hosted checkout may not both advance confirmation. First authoritative settlement/review wins under the order row lock; later money is review work.

## Tokens, consent, attribution and retention

- Session and recovery credentials are separate 64-character cryptographic random values returned only at issue/recovery. Only SHA-256 hashes persist. Session, recovery, quote, reservation-hold and checkout expiry clocks are separate. Ordinary rotation renews only authenticated session access. Recovery remains possible after session expiry until its independent deadline, then rotates both credentials and renews both clocks under a row lock; either previous credential becomes unusable. Explicit revocation and invalid/expired credentials fail with the same generic authentication/not-found behavior.
- Order reference is an opaque ULID and is not authentication. It is always paired with the property slug and bearer session token.
- Terms, privacy, cancellation, no-show and marketing are separate versioned sources and separate immutable decisions. Required policies must be accepted; marketing may be declined. Each snapshot freezes kind, version, content checksum, boolean, UTC time and a keyed hash of an IPv4 `/24` or IPv6 `/56` prefix—not a raw IP.
- Attribution accepts only `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term`, `referrer_host` and query-free `landing_path`, with control-character and length rejection. Analytics events use the safe event envelope and never guest contact, raw token, voucher, bank data or provider metadata.
- The default session-PII retention is 30 days. The singleton maintenance command uses the state-specific session, quote or hold deadline; accepted manual evidence whose hold lapses moves to `finance_review` without restoring inventory, so Finance can confirm recovered inventory or refund. After retention a dedicated `direct_booking.order.pii_scrubbed` maintenance event scrubs session/recovery/contact/checksum/attribution/IP-prefix fields even when the order was already revoked, preserves `confirmed_at`, and cleans an unshared abandoned provisional direct-booking Guest or records an explicit deferral. Consent content checksums and the immutable event ledger remain.

## Publication and payment readiness

`DirectBookingLaunchReadinessEvaluator` fails closed unless the property is active/enabled and every supported locale has effective, nonblank property copy/media/alt text plus exact category/program copy/media and terms, privacy, cancellation, no-show and marketing content. Every supported currency needs an active published rate plan/rule and at least one enabled payment capability. Hosted checkout additionally requires a connected payment integration, secret reference, nonblank provider account, exact charge currency and a provider supported by `PaymentGatewayFactory`; manual bank transfer requires an exact localized, effective instructions publication for every supported locale. The accessible fallback must be a query-free public HTTPS hostname (not credentials, localhost or an IP literal). Public media is only a query-free public HTTPS asset URL or an opaque `public-media://` reference; storage paths and signed private objects are forbidden.

All item/category/program, publication/item, capability/instruction and order/quote/reservation/payment-request associations are protected by tenant-and-property-inclusive database constraints. Model predicates are defense in depth; same-tenant cross-property links are rejected by PostgreSQL composite foreign keys and equivalent SQLite enforcement triggers.

## Consumer mock

Agent 08 can run the deterministic contract mock without Laravel:

```bash
php -S 127.0.0.1:8096 contracts/direct-booking/v1/mock-router.php
```

Append `?fixture_state=paid_needs_review` (or any state in `fixtures/order-states.json`) to an order URL to render recovery/failure screens. `fixtures/manifest.json` lists every screen fixture. This proves UI/contract behavior only; it does not prove booking, inventory, payment or confirmation integration.

Breaking changes after merge require a versioned API/fixture set and coordinator approval. Additive optional response fields must still pass safe-projection review.
