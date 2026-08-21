# P3-07A direct-booking operations runbook

Date: 2026-08-20

## Scope and authority

The public API is a tenant/property-resolved adapter over Inn's existing availability, commercial quote, reservation hold, deposit, payment-request, provider reconciliation, Finance evidence review, refund and confirmation services. It does not own a second inventory or pricing engine.

A browser return is never payment or confirmation authority. Hosted checkout stays `payment_pending` until the existing provider-event worker performs an authoritative payment lookup and matches connection, seller account, external reference, amount and currency. Manual evidence stays unconfirmed until an authorized Finance review posts the exact payment and the reservation service rechecks the live hold.

## Launch readiness

Public property, policy, search and order endpoints fail closed with a generic `booking_unavailable` response. An authenticated tenant member may inspect exact blocking reasons at:

```text
GET /api/v1/properties/{property-uuid}/direct-booking-readiness
X-Tenant-ID: {tenant-uuid}
Authorization: Bearer {staff-token}
```

Readiness requires an active/enabled property, supported locale/currency, safe HTTPS bot fallback, published property/category/policy content with accessible media, published commercial rules, and a usable payment capability for every advertised currency. Hosted capabilities require a connected canonical provider account, secret reference, matching charge currency and supported gateway. Manual transfer requires localized immutable instructions.

Do not expose readiness reason codes on anonymous endpoints. Disable direct booking before changing a required publication, payment connection or commercial version that would make an advertised journey incomplete.

## Public command safety

- Mutations require a canonical UUID `Idempotency-Key`. The same canonical body replays the encrypted exact response; a different body conflicts. Keep retry keys client-side and never put session, recovery, Turnstile or provider secrets in logs or analytics.
- Order access uses opaque 64-character bearer tokens bound to the property. Recovery consumes a separate token, rotates both credentials and returns the order to `started` for authoritative repricing.
- Required policy consents are separate immutable version/checksum facts. Marketing consent is optional and never inferred from required acceptance.
- Turnstile is verified server-side with the configured action and hostname. Failure, timeout or missing verifier configuration fails closed when bot verification is enabled. The accessible fallback remains a non-booking support path.
- Evidence uses content-derived MIME validation, scanner approval, hashed identity and private local storage. Upload alone never posts money.

The frozen v1 request contains `optional_service_keys`, but v1 exposes no public service-key discovery mapping. Until a versioned contract adds that mapping, nonempty optional-service selections fail safely; included services still come from the authoritative rate plan and appear in quote lines.

## Lifecycle and recovery

```text
started -> quoted -> held -> payment_pending -> paid_pending_confirmation -> confirmed
                         |                    \-> paid_needs_review -> Finance refund/reconcile
                         \-> awaiting_manual_payment -> evidence_pending -> finance_review -> confirmed
                                                           \-> evidence_rejected -> retry
eligible active states -> expired -> recovery/reprice
confirmed or paid_needs_review -> refunded (only after existing refund recovery completes)
```

- A hosted creation timeout is retried against the durable payment request and attempt identity. The existing checkout service reuses the active attempt/provider idempotency identity; never create a caller-selected provider reference.
- A rejected/canceled hosted attempt moves to `payment_failed`. Retry supersedes the old request and active attempt, issues a replacement against the still-valid authoritative hold, then creates checkout outside the database transaction.
- A checkout may extend the reservation hold once, never past the configured provider extension or absolute `maximum_hold_minutes`. The order, reservation and payment request expiries move together.
- Approved payment after expiry, lost inventory, mismatched identity/money/account, or failed reservation confirmation moves to `paid_needs_review` and creates a deduplicated priority Finance task. Do not create a replacement allocation until Finance establishes inventory safety.
- Provider refund execution/recovery remains owned by the P3-06A workflow. Only a fully recovered refund whose source payment is `refunded` advances the direct order to `refunded`. Ambiguous or failed refunds remain visible in the provider-refund recovery queue.
- Rejected manual evidence may be corrected while the hold is live. Finance approval after hold expiry creates refund/reconciliation work and does not confirm inventory.

## Scheduler, queues and incident checks

`direct-booking:maintain` runs every minute as the named `direct-booking:expire` schedule with `withoutOverlapping()` and `onOneServer()`. The daily named `direct-booking:cleanup` schedule scrubs retained order/session PII, consent IP-prefix hashes and eligible abandoned provisional guests, and deletes expired encrypted command responses. The existing reservation-hold, payment-request, provider-event, outbox, document and refund workers remain authoritative for their domains.

```bash
php artisan direct-booking:maintain --tenant=<tenant-uuid> --batch=100
php artisan direct-booking:maintain --tenant=<tenant-uuid> --batch=100 --cleanup
php artisan payments:reconcile <payment-attempt-uuid>
php artisan payments:recover-refund <provider-refund-uuid> <provider-refund-id>
php artisan payments:recover-refunds
```

Keep scheduler/queue cache shared between nodes. On queue outage, preserve committed holds/requests and resume the ordinary worker; never apply provider payload fields manually. On communication or document outage, retry the existing outbox/document request rather than re-recording payment. On suspected PII/token leakage, disable the property, rotate affected order/provider credentials, restrict logs/artifacts and follow the incident process.

## Release evidence boundary

The deterministic API/provider fake proves application state, lock, replay and accounting behavior. P3-06A's accepted Colombia/MCO + COP test-mode journey proves the shared provider/payment substrate, not a direct-booking production journey. Production activation still requires the final merchant connection, public HTTPS origin, provider-originated valid webhook, ordinary-worker processing and an observed anonymous booking/refund journey. Do not describe synthetic provider events, local Compose receipts or dashboard/test-signed deliveries as production evidence.

Primary implementation references: [Laravel transactions](https://laravel.com/framework/docs/13.x/database), [queues](https://laravel.com/framework/docs/13.x/queues), [rate limiting](https://laravel.com/framework/docs/13.x/rate-limiting), [Cloudflare Turnstile validation](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/), and [Mercado Pago Checkout Pro](https://www.mercadopago.com.ar/developers/en/docs/checkout-pro/overview).
