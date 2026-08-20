# P3-06A online payment requests, links, and Mercado Pago implementation plan

Date: 2026-08-18
Status: **software-controlled closure complete; Colombia/MCO provider journey partially proven; Argentina/ARS WP-11 release gate open**
Branch: `codex/p3-06-payment-gateway-mercado-pago`
Base: clean `main` at or after P3-03 merge `e459935`
Inputs: [Rincón Grande requirements](rincon-grande-requirements.md), [phase 3 plan](client-ready-phase-3-plan.md), [feature matrix](feature-matrix.md), [UAT ledger](client-uat-ledger.md), [reference benchmark](reference-code-quality-benchmark.md), [P3-06B front-desk tender plan](p3-06b-front-desk-tenders-implementation-plan.md), [P3-06C Point/QR plan](p3-06c-mercado-pago-point-qr-implementation-plan.md)

## 1. Decision and release boundary

The next implementation slice is a real provider-backed payment loop. It has two separate layers that must not be collapsed:

1. Inn owns a provider-neutral, reservation-scoped `payment_request` and guest-safe link lifecycle.
2. Mercado Pago Checkout Pro is the first hosted provider adapter used to execute that request.

The email/WhatsApp/guest-portal URL is an Inn URL, never a copied provider-dashboard link or an expiring provider checkout URL. Opening the Inn link resolves the current request state and creates or recovers a provider checkout attempt. That keeps the guest experience stable across provider-session expiry and makes a later adapter change possible without changing reservation accounting.

For the current Argentina/Patagonia lodge baseline, use **Mercado Pago Checkout Pro** as the first adapter:

- Argentina is supported by Checkout Pro; the buyer pays in Mercado Pago's hosted environment and returns to Inn.
- Mercado Pago supplies Argentina test buyers/cards and deterministic approved, pending, and rejected scenarios.
- Webhooks carry a secret-backed `x-signature`; the event is only a notification and Inn must retrieve the provider payment before mutating money.
- The provider supports partial and full refunds with `X-Idempotency-Key`.
- Stripe is not the default because Argentina is not on Stripe's current merchant-country availability list.

This provider choice is based on the current demo property and must be revalidated before production activation. The production merchant account, legal entity, settlement bank, fees, tax treatment, accepted international cards, and charge/settlement currencies remain launch decisions. They do not block building and proving the test-mode integration.

Primary implementation references:

- [Mercado Pago Checkout Pro overview](https://www.mercadopago.com.ar/developers/es/docs/checkout-pro/overview)
- [Argentina test purchases and deterministic card outcomes](https://www.mercadopago.com.ar/developers/en/docs/checkout-pro/integration-test/test-purchases)
- [Webhook events and provider-resource lookup](https://www.mercadopago.com.ar/developers/en/docs/your-integrations/notifications/webhooks)
- [Webhook `x-signature` validation](https://www.mercadopago.com.ar/developers/es/docs/checkout-bricks/additional-content/your-integrations/notifications/webhooks)
- [Partial/full refund API and required idempotency key](https://www.mercadopago.com.ar/developers/es/reference/online-payments/checkout-pro/create-refund/post)
- [Mercado Pago preference creation](https://www.mercadopago.com.ar/developers/es/docs/checkout-pro/create-payment-preference)
- [Hyperswitch payment-link lifecycle](https://docs.hyperswitch.io/explore-hyperswitch/payment-orchestration/quickstart/payment-links/create-payment-links) as an interaction and state-model reference only; Hyperswitch is not an Inn runtime dependency
- [Stripe merchant-country availability](https://stripe.com/global)
- [Mercado Pago official PHP SDK](https://github.com/mercadopago/sdk-php) for provider models and compatibility reference; Inn still owns the interface and transport decision

### Currency boundary

The first real provider rail is **ARS Checkout Pro**. Inn continues to support USD and ARS reservations:

- ARS reservations charge the exact outstanding/deposit amount in ARS.
- A USD reservation may be offered an ARS checkout only after Inn creates and displays an immutable USD → ARS conversion snapshot, the guest accepts the ARS amount, and the attempt stores both source and charge values.
- If no approved conversion snapshot exists, a USD reservation remains payable by the already-supported international bank-transfer workflow.
- Never relabel a USD amount as ARS, silently convert it, or treat the provider's ARS settlement as a USD payment.

### Scope included

1. Staff issuance, preview, delivery, resend, revocation and expiry of a reservation payment request.
2. An opaque Inn-owned guest link for an exact deposit, balance, full outstanding amount, or explicitly authorized partial amount.
3. Guest payment of that request through hosted Checkout Pro.
4. Provider checkout creation and recovery of an already-created checkout after timeout/retry or provider-session expiry.
5. Signed webhook receipt, durable storage, asynchronous retrieval, normalization, deduplication, reordering, and replay.
6. Exactly-once creation of an Inn provider-origin payment, folio posting, and optional deposit reconciliation after authoritative approval.
7. Pending, rejected, cancelled, expired, mismatched and approved states without trusting redirect query parameters.
8. Provider-backed partial/full refund request, retry, completion, failure, and reconciliation.
9. Claims/chargeback intake and a visible Finance exception queue.
10. Fee, net and settlement reconciliation sufficient to explain gross versus net receipts.
11. Payment/refund receipts using the immutable P3-03 document pipeline.
12. Filament operations, guest-link UX, guest-portal UX, API/OpenAPI contracts, audit records, runbook, and real test-mode browser evidence.

### Explicitly excluded

- Raw card collection or storage in Inn.
- Provider-dashboard payment links as the production workflow. They remain an emergency/manual fallback and do not satisfy P3-06A.
- Checkout Bricks, saved cards, subscriptions, recurring billing, card-on-file, manual capture, marketplace/split payments, crypto, gift cards, or multiple gateways.
- Standalone cash/external-terminal controls; those are P3-06B.
- Point/card-present and dynamic QR execution; those are P3-06C.
- Fiscal invoice issuance.
- Direct public booking inventory/search; that remains P3-07. P3-06A starts from an existing reservation/guest secure link.
- Production email delivery and Horizon; those remain P3-04.
- Managed hosting, secrets platform, backups, and production monitoring; those remain P3-05.

## 2. Non-negotiable invariants

| ID | Invariant |
| --- | --- |
| LINK-01 | A payment link resolves one immutable `payment_request`; it never accepts client-supplied amount, currency, reservation, deposit or provider identity. |
| LINK-02 | Only a hash of the opaque access token is stored. Issue/resend returns a token once; rotation or revocation invalidates prior links without deleting the request or its audit history. |
| LINK-03 | A request is tenant/property/reservation scoped, has one purpose, exact source amount/currency, expiry and creator. An amendment or correction never silently changes it; staff revoke and issue a replacement linked to the prior request. |
| LINK-04 | Opening or refreshing a link cannot create multiple payable provider sessions. At most one non-terminal attempt is reusable for the request; a replacement attempt is allowed only after the prior one is terminal or authoritatively expired. |
| LINK-05 | A request becomes `paid` only from the exactly-once Inn payment application. Browser return, email delivery and provider checkout creation cannot mark it paid. |
| LINK-06 | An expired/revoked/superseded/paid request never starts a new charge. It returns a guest-safe terminal state and no reservation or guest PII. |
| MP-MONEY-01 | Provider redirect state never posts money; only a signed event followed by an authenticated provider lookup, or an authenticated reconciliation lookup, can do so. |
| MP-MONEY-02 | One provider transaction creates at most one Inn payment and one folio payment effect under duplicates, retries, races, and event reordering. |
| MP-MONEY-03 | Reservation/deposit identity, tenant, property, external reference, source amount/currency, charged amount/currency, and provider account must match before approval is applied. |
| MP-MONEY-04 | Every amount is integer minor units in Inn. Decimal provider values are parsed with `Brick\Math`, never binary floating point. |
| MP-MONEY-05 | A USD → ARS checkout stores the immutable exchange-rate snapshot, direction, source, effective instant, rounding result, and guest-visible accepted amount. |
| MP-EVENT-01 | Raw request bytes and required headers are verified before JSON is trusted. Invalid/stale signatures produce no provider fetch and no state mutation. |
| MP-EVENT-02 | Provider events are append-only. Duplicate delivery records the duplicate/replay outcome without duplicating domain effects. |
| MP-EVENT-03 | Processing is safe when approved arrives before pending, pending arrives after approved, events are delayed, or the provider fetch disagrees with the notification. |
| MP-REFUND-01 | Provider refund execution must succeed or be authoritatively recovered before `CompleteRefund` posts the Inn refund/folio effect. |
| MP-REFUND-02 | Provider and Inn idempotency keys are stable across network retry. A timeout after remote success is resolved by lookup, not a new refund. |
| MP-SEC-01 | Access tokens and webhook secrets never enter the database, logs, browser, queue payloads, exceptions, snapshots, exports, or generated documents. |
| MP-AUTH-01 | Guests may create/recover checkout only for their active secure-link reservation. Finance controls refunds, replay, reconciliation, mismatch and dispute work. |
| MP-AUDIT-01 | Attempts, events, state decisions, payment/refund effects, reconciliation and operator actions retain timestamps, actor/system identity and bounded sanitized diagnostics. |

## 3. Provider boundary

Create an Inn-owned interface; do not call Mercado Pago from controllers, Filament actions, models, or domain services.

```php
interface PaymentGateway
{
    public function createHostedCheckout(CheckoutRequest $request): HostedCheckout;
    public function fetchPayment(string $providerPaymentId): ProviderPayment;
    public function refund(ProviderRefundRequest $request): ProviderRefund;
    public function fetchRefund(string $providerPaymentId, string $providerRefundId): ProviderRefund;
    public function verifyWebhook(WebhookRequest $request): VerifiedProviderEvent;
}
```

Implement `MercadoPagoCheckoutProGateway` with Laravel's HTTP client behind a small transport interface. Prefer the explicit REST contract over SDK-global configuration so each request has an explicit connection, secret reference, timeout, retry policy, idempotency key and fakeable response. No automatic retry is allowed for a mutation unless the same idempotency key is reused.

The existing `integration_connections` row remains the provider configuration anchor:

- `type = payment`
- `name = mercado-pago-argentina`
- non-secret configuration: provider, environment, site/country, charge currency, provider account identifier, enabled payment modes, return URL base
- `secret_reference`: reference to test/production access token and webhook secret in an approved secret store
- never store the access token or webhook secret in `configuration`

## 4. Persistence model

Add one ordered migration set after the P3-03 migrations. Use tenant-scoped UUIDs, explicit foreign keys, PostgreSQL checks, unique provider identities, and indexes for work queues. Migration order is `payment_requests` first, then attempts/events/refunds/settlements. Rollback order is the reverse. SQLite remains the fast contract gate; PostgreSQL owns row locks, partial indexes and production constraint proof.

### `payment_requests`

- tenant, property, reservation, optional deposit, creator and optional superseded/replacement request
- initiation mode starts as `guest_link`; P3-06C may extend the same request aggregate with staff-only Point/QR modes rather than inventing another money intent
- opaque public identifier plus access-token hash for `guest_link`; no raw token, guest email or reservation identifier in the URL
- purpose: `deposit`, `balance`, `full_outstanding`, `authorized_partial`
- state: `draft`, `open`, `processing`, `paid`, `expired`, `revoked`, `superseded`
- immutable source amount/currency and calculation snapshot/checksum
- optional charge-currency/conversion policy; actual accepted conversion is copied to the attempt
- `expires_at`, `opened_at`, `last_opened_at`, `paid_at`, `revoked_at`, `revoked_by`, reason and bounded access count
- optional resulting `payment_id`; this is set only by the provider-payment application transaction
- unique opaque public identifier and token hash
- one resulting payment per request; a payment cannot satisfy two unrelated requests
- indexes for open/expiring requests, reservation history and Finance exceptions

### `payment_attempts`

- tenant, property, reservation, payment request, optional deposit, integration connection
- provider/environment/provider account
- stable internal `external_reference` and command idempotency key
- purpose: `deposit`, `balance`, `custom_authorized_amount`
- state: `creating`, `checkout_ready`, `pending`, `approved`, `rejected`, `cancelled`, `expired`, `mismatched`, `failed`
- source amount/currency and charge amount/currency
- nullable exchange-rate snapshot plus copied immutable conversion metadata
- provider preference/order/payment IDs and hosted checkout URL
- payer email hash or redacted identifier only; no card data
- checkout expiry, provider status/detail, attempt/error counters, last checked/processed timestamps
- unique `(tenant_id, provider, environment, external_reference)`
- unique nullable `(tenant_id, provider, environment, provider_payment_id)`
- enforce at most one reusable non-terminal attempt per payment request with a PostgreSQL partial unique index or an equivalent lock-and-constraint design

### `provider_events`

- tenant, connection, provider/environment/account
- provider topic/type/action/event or resource ID
- signature result, received time, provider-created time, processing state
- raw-body checksum and encrypted/private raw payload or a strictly allow-listed canonical payload; define retention explicitly
- sanitized headers required for verification and correlation
- attempt/error, processed time, duplicate-of linkage
- unique provider delivery identity where supplied; otherwise unique canonical deduplication checksum
- never mutate or delete financial event history through ordinary application actions

### Provider refund linkage

Keep `reservation_changes` as the Inn refund/audit lifecycle. Add provider execution fields in a dedicated `provider_refunds` table linked to the requested/completed change and original payment:

- requested source amount/currency and provider charge amount/currency
- stable idempotency key
- provider payment/refund IDs
- `requested`, `processing`, `succeeded`, `failed`, `mismatched`
- response checksum, bounded error, attempt timestamps
- unique `(tenant_id, provider, environment, provider_refund_id)` and stable command key

### Settlement reports and revisions

- `settlement_report_imports` is the immutable report envelope: provider/environment/account, report type, provider report ID/revision, original filename, exact file SHA-256, reporting dates, currency, provider generation metadata and fixture marker.
- `settlement_report_rows` stores a deterministic row identity and occurrence ordinal plus an explicit allow-list of reconciliation fields. Payer/guest columns, credentials and the original raw row are not retained.
- Account Money and Released Money are separate contracts. Account Money `SETTLEMENT_DATE` is approval time and `MONEY_RELEASE_DATE` is the expected release time; Released Money `DATE` is the actual balance movement and `RECORD_TYPE` is authoritative before localized/free-text `DESCRIPTION`.
- payment lookup facts are not payout proof. Provider payment `money_release_schema` and `status_detail` are never relabeled as payout/settlement identity or status.
- `settlement_entries` keeps provider payment/refund/dispute identity and nullable payout identity/date/status, gross, fee, nullable tax/withholding/financing/refunded/chargeback/net minor units, explicit currencies and reconciliation state.
- `settlement_entry_revisions` is append-only. Reimporting the same exact report is a no-op; the same provider report ID with changed bytes produces a new immutable import/revision and visible variance.
- Account-level withdrawal/payout and Released Money availability movements remain account-level and are never attached to a payment merely because they share a report.

## 5. Application commands and event processing

Implement narrow commands with database transactions and consistent lock order:

1. `IssuePaymentRequest`
   - authorize Sales/Finance and lock reservation, selected deposit and folio projection;
   - calculate the payable amount from authoritative server state;
   - reject zero, negative, over-outstanding, incompatible currency and ineligible reservation states;
   - persist an immutable calculation snapshot and generate a cryptographically random token;
   - store only the token hash, return the plain token once, and queue delivery only after commit;
   - an idempotent replay returns the existing request projection and must not rotate its token implicitly.
2. `RotateOrResendPaymentRequest`
   - resend without rotation reuses an already-encrypted delivery intent or issues a new access grant without changing money;
   - rotation invalidates the prior token and creates an audited successor access grant;
   - never extend the financial request expiry without an explicit authorized command and reason.
3. `RevokeOrSupersedePaymentRequest`
   - lock request and reservation;
   - refuse to revoke a paid request or hide an approved provider payment;
   - cancel/expire an open provider attempt when the provider contract permits;
   - amendments and amount changes create a linked replacement rather than updating the original amount.
4. `ResolvePaymentRequest`
   - hash and constant-time match the presented token;
   - apply dedicated rate limiting independent of the guest-portal invitation limiter;
   - return an allow-listed guest projection for open and terminal states;
   - record bounded access metadata without storing the token or PII in logs.
5. `CreateProviderCheckout`
   - authorize guest/staff and lock reservation/deposit;
   - lock and validate the payment request before calculating any provider request;
   - calculate remaining payable amount from authoritative folio/deposit state;
   - lock/create immutable conversion when needed;
   - create the local attempt before the remote call;
   - call the provider with the attempt UUID as stable external reference;
   - recover by external reference/provider lookup after timeout instead of creating a second attempt;
   - return an allow-listed hosted URL only.
6. `ReceiveProviderWebhook`
   - capture raw bytes/headers;
   - resolve the connection without trusting a caller-supplied tenant ID;
   - verify HMAC with timestamp tolerance and constant-time comparison;
   - persist event and return `200` quickly;
   - dispatch processing only after commit.
7. `ProcessProviderEvent`
   - claim event exactly once;
   - fetch provider resource using server credentials;
   - normalize provider state;
   - find the attempt by provider account plus external reference/payment ID;
   - validate identity/amount/currency;
   - on approval, lock reservation → payment request → attempt → payment/deposit in the established order and invoke a new provider-only `PaymentService` entry point exactly once;
   - mark the request paid and attach `payment_id` inside that same database transaction;
   - mismatches and unknown references enter Finance work without posting money.
8. `ReconcileProviderPayment`
   - safe manual/periodic recovery for webhook loss, timeout and poison-event repair;
   - never bypass the same validation and exact-once application service.
9. `ExecuteProviderRefund`
   - begins from an authorized Inn refund request;
   - locks reservation → payment → refund request/provider refund;
   - verifies the remaining refundable amount;
   - calls provider with stable idempotency key;
   - fetches after ambiguous timeout;
   - invokes `CompleteRefund` only after authoritative provider success.
10. `RecoverProviderRefund`
   - performs an authoritative fetch for provider-dashboard refunds or ambiguous execution;
   - locks and commits Inn accounting plus provider execution success together;
   - preserves an already-succeeded recovery without another provider call;
   - is available through authorized Finance actions, CLI, and scheduled stuck-refund recovery.
11. `RecordProviderDispute` and settlement reconciliation
   - record claims/chargebacks without rewriting the original payment;
   - preserve complete provider facts in append-only revisions and post only the remaining reversible amount;
   - surface amount/status mismatch and net settlement variance to Finance;
   - import deterministic Account Money and Released Money reports with exact decimal parsing and immutable source checksums.

## 6. API and user experience

### Guest portal

- Staff-issued messages link to `GET /pay/{opaqueToken}` on Inn. Never email `init_point`, a provider preference ID, or a provider-dashboard-generated link.
- The payment page reveals only property display name, reservation-safe reference, purpose, exact source/charge amount, currency, expiry, policy text and request state.
- The same link can be refreshed while open. A revoked, superseded, expired or paid link shows a terminal explanation and cannot create another attempt.
- Show due deposit and outstanding folio amounts using authoritative server values.
- Offer `Pay securely with Mercado Pago` only when the connection, currency path and reservation state allow it.
- For USD → ARS, show original USD, locked rate/source/time, charged ARS amount, expiry and explicit acceptance.
- Redirect to hosted Checkout Pro; do not embed or collect card fields.
- Return pages show `processing`, `pending`, `approved`, `failed`, or `expired`, but state that final confirmation may take time.
- Refresh/retry recovers the existing attempt and cannot produce a second charge.
- Approved payment exposes the P3-03 payment receipt after generation.

### Filament Finance workspace

- Payment-request table/detail with issue, preview, copy link, queue delivery, resend, rotate token, revoke and replace actions. Every action requires confirmation where money or access changes and records a reason.
- Payment-attempt table/detail with reservation, source/charge money, provider IDs, status/detail, age, last event and reconciliation state.
- Event ledger with signature/processing result and sanitized payload metadata.
- Actions: fetch/reconcile, replay failed event, mark investigated with reason, request/execute/recover refund, generate receipt, and investigate/resolve settlement variance with actor and note.
- Separate mismatch, failed event, pending-too-long, dispute, settlement-report exception and settlement-variance queues.
- Provider-origin payments cannot use manual reconcile/reverse actions that bypass the provider workflow.

### HTTP/OpenAPI

Add and document these route families; exact resource naming may follow existing route conventions, but the boundaries may not be merged into one controller:

- authenticated staff: issue/list/show/resend/rotate/revoke/replace payment requests under a reservation;
- public guest link: resolve request, create/recover checkout and read allow-listed status;
- provider return: display attempt status only; never mutate money;
- public provider webhook: raw-body receipt under an opaque connection key, not a caller-supplied tenant/property ID;
- authenticated Finance: reconcile/replay attempt/event, execute/recover refund and resolve mismatch/dispute/settlement work;
- read projections for request, attempt, event, refund and settlement resources.

Every mutating authenticated endpoint uses Inn idempotency middleware. Payment-link token operations use their own rate limiter and request/attempt locks. The public webhook uses provider signature verification and provider-event uniqueness instead. OpenAPI examples must contain synthetic/redacted provider IDs and no reusable secrets.

## 7. Work packages

| WP | Deliverable | Completion proof |
| --- | --- | --- |
| WP-01 | Payment-request/link schema, enums, policy and commands | Issue/resolve/resend/rotate/revoke/replace tests prove immutable amount, hashed-token storage, terminal-state denial and tenant/property isolation |
| WP-02 | Provider decision/configuration and attempt/event/refund/settlement migrations | Migration tests on SQLite and PostgreSQL; constraints reject cross-tenant links, invalid states/currencies and duplicate provider identities |
| WP-03 | Gateway interface, Mercado Pago transport and response normalization | Contract fixtures for preference creation, payment fetch, signature verification, refund/fetch, 401/403, 404, 409, 429, 5xx, malformed JSON and timeout-after-success |
| WP-04 | Checkout command and currency conversion | Exact request amount; USD → ARS immutable consent snapshot; no rate/mismatch means no remote call; duplicate clicks recover one reusable attempt |
| WP-05 | Webhook intake and event processor | Invalid/stale signature denied; valid duplicate/reordered/delayed events produce one domain effect |
| WP-06 | Provider-origin payment application | One provider payment, request satisfaction, folio line and deposit allocation under concurrent PostgreSQL workers and HTTP replay |
| WP-07 | Provider refunds | Partial/full success, failure, insufficient provider balance, timeout recovery and concurrent duplicate execution; Inn completion occurs once |
| WP-08 | Dispute, fee and settlement reconciliation | Append-only claim/chargeback handling and visible unmatched/variance queues without rewriting payment history |
| WP-09 | Guest and Finance UX, API and OpenAPI | Role/property/link allow-deny tests plus accessible phone and desktop states |
| WP-10 | Documents, delivery intent, audit, logging and operations | Payment/refund receipts, link delivery/resend audit, secret-redaction assertions, replay runbook, metrics/log events and bounded retention |
| WP-11 | Real provider sandbox UAT and release gates | Staff-issued Inn link → hosted test checkout approved/pending/rejected → signed notification → exactly-once Inn effect → partial refund and receipt demonstrated end to end |

## 8. Ordered agent implementation checklist

The implementing agent follows this order and does not build Filament pages before the persistence and command invariants pass:

1. **Baseline and branch proof**
   - confirm the branch is `codex/p3-06-payment-gateway-mercado-pago`, based on synchronized `main` at `e459935` or later;
   - run the focused existing payment/refund tests, full fast suite and `make test-api-postgres` before editing code;
   - record exact baseline counts in the implementation evidence section without overwriting prior P3-03 evidence.
2. **Enums and migrations**
   - add enum-backed request/attempt/event/provider-refund/reconciliation states under `apps/api/app/Enums`;
   - add ordered migrations under `apps/api/database/migrations` for `payment_requests`, `payment_attempts`, `provider_events`, `provider_refunds` and `settlement_entries`;
   - add PostgreSQL checks/partial uniqueness plus portable Laravel validation for SQLite;
   - add migration/constraint tests before models or UI.
3. **Models, relationships, factories and policies**
   - add tenant-scoped models and explicit casts/guarded attributes;
   - connect Reservation, Deposit, Payment, ReservationChange and IntegrationConnection without adding a second financial aggregate;
   - add factories and policy tests for Sales, Finance, Viewer, guest-link and cross-property boundaries.
4. **Payment-request commands**
   - implement `IssuePaymentRequest`, `RotateOrResendPaymentRequest`, `RevokeOrSupersedePaymentRequest` and `ResolvePaymentRequest` under `apps/api/app/Services/Payments`;
   - extend the communication outbox with a delivery intent only; P3-04 still owns real provider delivery events;
   - implement a dedicated `payment-request-link` rate limiter and token-redaction middleware/log context;
   - pass unit, feature, property/tenant and PostgreSQL race tests.
5. **Provider contract**
   - add DTOs/value objects and `PaymentGateway` under `apps/api/app/Contracts/Payments` and `apps/api/app/Data/Payments` or the repository's nearest established equivalents;
   - implement a fake gateway for deterministic tests;
   - implement `MercadoPagoCheckoutProGateway` plus a narrow Laravel HTTP transport under `apps/api/app/Integrations/Payments/MercadoPago`;
   - load credentials only through configuration/secret references and prove redaction in logs, exceptions and serialized jobs.
6. **Checkout and webhook processing**
   - implement create/recover checkout, raw webhook receipt, queued event processing and authenticated payment reconciliation;
   - register queue names, retry/backoff/timeout/`retryUntil`, after-commit dispatch and terminal failure handling;
   - add provider contract fixtures for every documented status and HTTP failure class.
7. **Exactly-once Inn application**
   - add a provider-only entry point to the existing `PaymentService`; do not make `recordManual()` accept provider claims;
   - use the established reservation/payment lock order and existing deposit/folio services;
   - add real PostgreSQL races for duplicate checkout, concurrent event workers, manual/provider collision, refund/reversal collision and timeout recovery.
8. **Refund, dispute and settlement operations**
   - connect `RequestRefund`/`CompleteRefund` to provider execution without changing their append-only semantics;
   - implement ambiguous-result recovery, dispute records and settlement variance queues;
   - block legacy manual reversal/reconcile actions on provider-origin payments.
9. **HTTP, OpenAPI, guest UX and Filament**
   - add Form Requests, API Resources and narrow controllers for each route family;
   - update `apps/api/openapi.yaml` and contract tests in the same change;
   - add accessible public payment-request pages and guest-portal projections;
   - add Finance resources/actions only after policy, state and command tests pass.
10. **Documents, UAT and operational evidence**
    - request P3-03 payment/refund documents from immutable payment/refund snapshots;
    - add deterministic Playwright coverage with a fake gateway and a separately invoked real Mercado Pago sandbox journey;
    - add webhook replay/reconciliation and secret-rotation runbook instructions;
    - update the phase plan, feature matrix and UAT ledger only to the level proven by executable evidence.

## 9. Required test matrix

### Domain and persistence

- token entropy/hash storage, invalid token, token rotation, resend, revocation, supersession, request expiry and terminal paid-link behavior;
- amendment/price change after issue, deposit replacement, already-paid request, two simultaneous issue commands and request/checkout races;
- no PII/provider URL in the public URL, access logs, audit metadata, notifications, queue payloads or exception context;
- zero/negative/over-outstanding checkout requests; exact deposit, balance, partial authorized amount and overpayment rejection;
- USD/ARS identity/direct/inverse conversion, missing/stale rate, half-up boundary rounding and attempt expiry;
- cancelled/no-show/expired/checked-out/closed-folio reservation eligibility;
- already-paid deposit, simultaneous staff reconciliation and provider approval, two checkout requests, two event workers and payment/refund collisions;
- tenant/property/guest-link/role isolation on every query and action.

### Provider contracts and failure classes

- approved, pending/in-process, rejected, cancelled and expired provider payments;
- invalid or stale signature, missing headers, malformed JSON, unknown topic/account/external reference;
- duplicate delivery, same resource under different deliveries, approved-before-pending, pending-after-approved, delayed approval and chargeback-after-approval;
- provider timeout before response, timeout after remote success, 429 with retry window, 5xx, malformed response and credential failure;
- amount, currency, payer/account and external-reference mismatch;
- total and partial refund, duplicate refund, insufficient provider balance, refund after the provider window, timeout-after-success and event/lookup disagreement;
- fees, withholding, refund and chargeback causing net-settlement variance.

### Browser journeys

1. Staff creates/confirms a reservation and due deposit, issues an Inn payment request and previews/copies its Inn-owned link.
2. A second staff action resends without changing money; an explicit rotation invalidates the old link and the new link resolves.
3. Guest opens the phone-sized payment page and chooses hosted payment.
4. For the ARS path, Checkout Pro test buyer completes an approved card payment.
5. The return page remains non-authoritative until the signed event and provider lookup complete.
6. Guest sees the request/deposit paid exactly once and downloads the payment receipt; refresh cannot start another charge.
7. Finance sees the request, provider attempt/event/payment and reconciled gross/fee/net data.
8. Finance requests and executes a partial refund; the provider confirms it; Inn posts one refund and generates the refund receipt.
9. Separate runs prove revoked, superseded, expired, pending, rejected and retry/recovery states without a false payment.

Mocks, fixture payloads and a fake gateway are required for deterministic CI but do not complete WP-11. Completion requires Mercado Pago test credentials, an Argentina seller/test-buyer context, an HTTPS webhook URL, and recorded test-mode provider IDs with secrets redacted.

## 10. Release gates

The branch may merge only when all of these pass together:

1. focused payment unit/feature/contract tests;
2. the full SQLite suite;
3. isolated PostgreSQL 18 suite with real payment/event/refund races against `inn_test`;
4. authenticated and public Playwright suites plus the state-changing provider sandbox journey;
5. Pint and PHPStan with zero errors;
6. ESLint, TypeScript and production builds;
7. OpenAPI validation and response-contract tests;
8. Docker health/smoke, dependency audits and `git diff --check`;
9. log/exception/queue-payload assertions proving tokens, secrets, raw card data and sensitive provider fields are absent;
10. updated feature matrix, UAT ledger, phase plan and operations runbook with exact evidence.

## 11. Agent handoff

Start only from synchronized `main`:

```bash
git switch main
git pull --ff-only
git switch -c codex/p3-06-payment-gateway-mercado-pago
```

If the planning branch already exists, verify its base and preserve the uncommitted planning documents instead of recreating or resetting it. Do not create P3-06B, P3-06C, P3-04, P3-05 or P3-07 from the unmerged P3-06A branch. After P3-06A merges, return to synchronized `main` before creating `codex/p3-06b-front-desk-tenders`.

Do not mark the slice complete with only `Http::fake()`, a provider-dashboard payment link, manually inserted provider-origin payments, simulated unsigned callbacks, redirect query parameters, or a settings screen. The release claim is **Inn-owned reservation payment links plus Mercado Pago test-mode hosted checkout and provider-backed refund**, not generic card processing, card-present processing or production activation.

## 12. Implementation evidence — updated 2026-08-20

Implemented on `codex/p3-06-payment-gateway-mercado-pago` from baseline `e459935`:

- ordered request/attempt/event/refund/dispute/settlement schema with tenant composite foreign keys, provider-account/environment identity, money/state checks, PostgreSQL partial uniqueness, immutable report imports/rows/revisions, and upgrade/rollback proof;
- hashed one-time link issuance, rotation, expiry, revocation, authoritative amount calculation, ARS direct charge and explicit current USD→ARS snapshot acceptance;
- provider-neutral gateway/factory/DTO boundary plus the Checkout Pro REST preference, payment lookup, raw `x-signature` verification, refund, and exact decimal normalization adapter;
- encrypted provider-event persistence, signed HTTP intake, normal-worker `provider-events` consumption, lease/crash recovery, duplicate/mismatch handling, and exactly-once provider-only payment/deposit/folio/receipt application;
- provider refund execution plus authoritative recovery, atomic completion, scheduled stuck-refund recovery, and dashboard-refund fallback without duplicate accounting;
- append-only disputes and complete fact revisions, remaining-amount chargeback protection, immutable settlement revisions, deterministic official Account Money/Released Money CSV import, account-level payout isolation, and Finance variance actions;
- staff API/OpenAPI and Filament issue/rotate/revoke flow, phone-safe guest link and non-authoritative return pages, Finance attempt/event/refund/dispute/settlement queues, and reconcile/replay/expiry/report-import commands;
- real HTTP bad/missing/stale signature, unknown resource and throttling coverage; property/record authorization; scheduler lease/boundary/retry coverage; and PostgreSQL races for duplicate workers, manual reconcile versus webhook, refund execute versus recover, refund versus chargeback, settlement replay, and expiry versus approval.
- local Filament/guest browser UAT: staff issue → 390×844 exact-money guest state → rotation makes the old link `404` → replacement resolves → revocation shows a terminal non-payable page; the test request was revoked afterward and browser logs were clean.

Latest deterministic verification: SQLite `325` tests (`310` passed, `15` expected PostgreSQL-only skips; `2,396` assertions), PostgreSQL `325` tests (`324` passed, `1` expected Docker host-path skip; `2,433` assertions), authenticated Playwright `7/7` with the explicit provider spec separately gated/skipped, public Playwright `4/4`, PHPStan `0` errors, Pint clean, ESLint/TypeScript clean, API/web production builds clean, OpenAPI `93` paths / `128` operations / `102` resolved references, Composer/npm audits clean, Docker services healthy under the full smoke gate, and the explicit provider Playwright journey `1/1`. The provider browser journey proved mobile guest link → hosted deterministic checkout → informational return → signed HTTP webhook consumed by the ordinary Compose worker → exactly one payment/deposit/folio effect and automatic payment receipt PDF → Finance settlement investigation → partial refund authoritative recovery and refund receipt PDF. The final secret scan is recorded in the PR evidence before publication.

Baseline before code changes: focused financial/documents `36` tests (`30` passed, `6` skipped), SQLite `280` tests (`274` passed, `6` skipped; `1,921` assertions), PostgreSQL `280` tests (`279` passed, `1` skipped; `1,897` assertions).

## 13. Provider evidence — updated 2026-08-20

Secrets are configured only in the ignored, mode-`600` API environment file and were injected into the API and queue workers. The public webhook used a temporary HTTPS tunnel; neither the URL key nor its signing secret is retained in Git.

Completed with a Colombia/MCO test seller and buyer:

- real Checkout Pro COP 10,000 card approval, provider payment `…7197`;
- valid `x-signature` delivery accepted through the public endpoint, followed by authoritative provider lookup;
- duplicate delivery and three browser refreshes retained exactly one provider-origin payment, one paid deposit, and one COP -10,000 folio payment line;
- authoritative settlement evidence: COP 10,000 gross, COP 1,344 Mercado Pago fee, COP 41.40 ICA withholding, COP 150 withholding tax, and COP 8,464.60 net; the settlement entry remains a truthful variance because tax withholdings are separate from the modeled provider fee;
- COP 2,000 partial provider refund, provider refund `…6852`, completed in Mercado Pago's seller UI after the account policy rejected the refund API call, then reconciled by authoritative API lookup into exactly one completed Inn refund and one folio refund line;
- authenticated payment and refund receipt endpoints returned integrity-matching PDFs; both one-page A4 artifacts rendered cleanly;
- the approved return was exercised under a requested 390×844 mobile device viewport without creating another accounting effect.

Observed limitations and release classification:

- the runbook requires an Argentina seller/test buyer and ARS; the available personal developer account is Colombia/MCO and COP;
- the Colombia hosted flow rendered manual `CONT` and `OTHE` test cards as `UNDEFINED SOURCE` and kept the final payment action disabled;
- direct provider payment and refund calls returned `PA_UNAUTHORIZED_RESULT_FROM_POLICIES`, so those API paths cannot substitute for the blocked hosted pending/rejected cases on this account;
- the signed-delivery test used Mercado Pago's documented HMAC format and a real provider payment. Dashboard/test simulation plus authenticated lookup is the supported sandbox-notification proof; production activation separately requires a real provider-signed delivery through public HTTPS.
- the authorized MCO developer token successfully created redacted MLA test seller/buyer identities and an ARS preference, but the preference remained hosted on the Colombia site and the test-user response did not provide an MLA seller access token. No credentials were retained. A same-country MLA seller application/access token is therefore an external provider/account gate, not a remaining software task.

WP-11 therefore remains open. Keep the pull request draft and describe it as deterministic implementation plus MCO provider evidence, not Argentina sandbox-complete or production-ready. P3-06B remains blocked until this release criterion is met or explicitly changed.
