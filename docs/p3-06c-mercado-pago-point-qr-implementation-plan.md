# P3-06C Mercado Pago Point and QR implementation plan

Date: 2026-08-20
Status: **implemented and release-gated at level 1; provider QR sandbox and physical Point certification remain externally blocked**
Branch: `codex/p3-06c-mercado-pago-point-qr`
Base: synchronized `main` containing the merged [online payment](p3-06-mercado-pago-payments-implementation-plan.md) and [front-desk tender](p3-06b-front-desk-tenders-implementation-plan.md) slices

## Implementation result

The software slice now includes a dedicated Mercado Pago Orders gateway, canonical connection/account/environment/property binding, Point terminal and QR POS registries, staff initiation/cancel/reconcile/refund commands, signed singular-`order` event processing, exact-once provider payment/settlement/receipt application, Finance/Filament operations, API/OpenAPI contracts, PostgreSQL active-order constraints, deterministic worker/browser UAT, and a local-only authorized Point virtual-device harness.

Completion is intentionally split into three evidence levels:

1. **Implementation/test mode:** complete after the recorded release gates in [`docs/evidence/p3-06c/README.md`](evidence/p3-06c/README.md). This proves deterministic Point and QR Orders, signature/lookup handling, ordinary-worker accounting, receipts, settlement, and browser behavior; it is not provider transaction evidence.
2. **Real QR sandbox:** not run. The available authorized test connection identifies a Colombia/MCO test account, while the requested QR Orders path is an Argentina/MLA + ARS merchant/POS/test-buyer capability. No provider mutation was attempted with an unsupported account.
3. **Client-complete physical Point:** not run. It requires an authorized production MLA merchant, a real Point device confirmed in PDV mode, a low-value card payment, refund/terminal action when required, receipt, and settlement observation. Mercado Pago says test users cannot complete real physical Point payments and its virtual device is not an integration-quality measurement.

The operating procedure and activation checklist are in [`docs/p3-06c-mercado-pago-point-qr-runbook.md`](p3-06c-mercado-pago-point-qr-runbook.md).

## 1. Decision and client outcome

P3-06C adds automatic card-present and QR collection without allowing Inn to handle card data:

1. Staff selects an exact reservation/deposit/folio amount in Inn.
2. Inn creates an idempotent Mercado Pago Orders API order for a configured Point terminal or QR point of sale.
3. The terminal receives the order automatically, or Inn displays the provider-generated dynamic QR.
4. The guest taps/inserts/swipes a card or scans the QR.
5. Inn treats the return/device screen as informational, verifies the signed provider event and fetches the order authoritatively.
6. Exactly one provider-origin Payment, folio effect, optional deposit application, receipt and settlement record results.

Use **Mercado Pago Point Smart plus Mercado Pago QR through the unified Orders API** as the first integrated in-person adapter. This reuses the P3-06A Mercado Pago connection, transport, event ledger, payment application, refund and reconciliation infrastructure.

Primary references:

- [Mercado Pago Point overview](https://www.mercadopago.com.ar/developers/es/docs/mp-point/overview)
- [Configure a Point terminal and store/POS association](https://www.mercadopago.com.ar/developers/es/docs/mp-point/configure-terminal)
- [Point payment processing](https://www.mercadopago.com.ar/developers/es/docs/mp-point/payment-processing)
- [Point Orders API idempotency, cancellation and refund migration](https://www.mercadopago.com.ar/developers/es/docs/mp-point-v2/migrate-payment-intent-to-orders)
- [Point virtual-device integration tests](https://www.mercadopago.com.ar/developers/es/docs/mp-point/integration-test)
- [QR overview](https://www.mercadopago.com.ar/developers/es/docs/qr-code/overview)
- [QR Orders API static/dynamic/hybrid processing](https://www.mercadopago.com.ar/developers/es/docs/qr-code/payment-processing)
- [QR Orders API migration and required idempotency](https://www.mercadopago.com.ar/developers/es/docs/qr-code/migrate-dynamic-qr-model-to-orders)
- [Orders API QR create reference](https://www.mercadopago.com.ar/developers/es/reference/in-person-payments/qr-code/orders/create-order/post)
- [Mercado Pago webhook verification and authoritative resource lookup](https://www.mercadopago.com.ar/developers/es/docs/your-integrations/notifications/webhooks)

### Why not Hyperswitch

Hyperswitch remains a code-quality and state-model reference, not a runtime dependency. Revalidate this decision when implementing the slice, but the inspected 2026-08-18 `main` state had no Mercado Pago, Mobbex or Payway connector. Its connector named Getnet targeted `api.getneteurope.com`, was marked Alpha and did not advertise 3DS. Running Hyperswitch would add router, database, cache, control-center, vault/encryption and connector operations without supplying the Argentine terminal integration.

References:

- [Hyperswitch architecture and supported-connector model](https://github.com/juspay/hyperswitch)
- [Hyperswitch connector registry](https://github.com/juspay/hyperswitch/blob/29fafe4d9d511d1838d397321defb041075a6060/crates/hyperswitch_connectors/src/connectors.rs)
- [Inspected Getnet connector status and capabilities](https://github.com/juspay/hyperswitch/blob/29fafe4d9d511d1838d397321defb041075a6060/crates/hyperswitch_connectors/src/connectors/getnet.rs)

Adopt an orchestrator only after two or more live PSPs, measured routing/failover value, portable-token requirements and an approved operational/compliance owner exist.

### Mobbex alternative gate

Mobbex Smart POS is the most relevant Argentine alternative because it combines standalone/integrated POS, QR, checkout and multi-acquiring. Do not implement it speculatively in P3-06C. The published plan currently places Smart POS and multi-acquiring in Enterprise with a stated ARS 70,000,000 monthly minimum. A later provider decision may replace/add it only after a signed merchant quote and the P3-06A security/recovery bake-off pass.

- [Mobbex Smart POS](https://www.mobbex.com/smart-pos/)
- [Mobbex plans](https://www.mobbex.com/planes/)

## 2. Scope

### Included

1. Tenant/property-scoped terminal and QR point-of-sale registry mapped to one Mercado Pago integration connection.
2. Point operating-mode/configuration visibility and safe enable/disable health state.
3. Staff-initiated Point order for exact deposit, balance, full outstanding or approved partial amount.
4. Dynamic QR order displayed in Inn; static/hybrid QR only when the property has a correctly provisioned store/POS mapping.
5. Order create/fetch/cancel/refund with stable provider idempotency keys.
6. Signed webhook/event processing and periodic/manual reconciliation using P3-06A infrastructure.
7. Exactly-once provider-origin payment, deposit/folio application and P3-03 receipt.
8. Terminal/QR-specific timeout, device-busy, abandoned, declined, cancelled, expired, refunded and mismatch work queues.
9. Virtual Point device and QR test-buyer automation plus supervised real-hardware UAT.
10. Provider fee/net/settlement reporting by channel, terminal/POS and reservation.

### Explicitly excluded

- PAN, CVV, expiry, track/chip/NFC payload collection or storage.
- Keyed card entry, virtual terminal, mail/telephone order entry or custom PIN UI.
- Saved cards, recurring/off-session charges, preauthorization/no-show capture and token vaulting.
- Tips, restaurant table service, retail catalogue/inventory and cash-drawer hardware.
- Offline authorization and accepting a terminal screen/printed receipt as provider truth.
- Automatic smart routing or a second integrated PSP.
- Production activation without merchant account, device, rate, settlement, refund and foreign-card approval.

## 3. Non-negotiable invariants

| ID | Invariant |
| --- | --- |
| POS-01 | Staff cannot type the charge amount sent to the provider. Inn derives it from a locked, immutable internal payment request and authoritative outstanding balance. |
| POS-02 | Each provider create/cancel/refund mutation has a stable operation-specific `X-Idempotency-Key`; retry with a different body is rejected locally before a provider call. |
| POS-03 | One terminal may have at most one active order controlled by Inn. Device-busy and already-queued responses enter recovery, never blind retry. |
| POS-04 | Terminal display, browser polling and staff confirmation never post money. Signed event plus authenticated lookup, or authenticated reconciliation lookup, is authoritative. |
| POS-05 | Provider account, order type, external reference, terminal/POS, amount, currency and payment identity must match the local attempt before money is applied. |
| POS-06 | One approved provider transaction creates at most one Payment, request satisfaction, folio effect, deposit application, receipt and cashless settlement entry under all retries/races. |
| POS-07 | Point and QR are separate channels sharing one Orders transport. A Point order cannot be reconciled as QR or vice versa. |
| POS-08 | Terminal/POS credentials and access tokens remain server-side; public QR payload contains only provider-specified EMVCo data and expires with its order. |
| POS-09 | A cancelled/expired/failed local order can be replaced only after authoritative provider state confirms it is non-payable. Late approval of a replaced order becomes a Finance exception and cannot double-apply. |
| POS-10 | Refund completion occurs only after authoritative provider success/recovery and uses the original payment/order/channel identity. |
| POS-11 | Disabling or replacing a terminal blocks new orders but preserves every historical attempt, event, payment, refund and settlement link. |
| POS-12 | No card-present capability is marked client-complete without a real Point device transaction, real receipt, refund and settlement/reconciliation observation under explicit live-test authorization. |

## 4. Persistence and provider boundary

### `payment_terminals`

- tenant, property, integration connection and optional staff-facing location;
- channel capability: `point`, `qr_static`, `qr_dynamic`, `qr_hybrid`;
- provider terminal ID or external POS ID, provider store ID and non-secret account alias;
- display name, hardware model/serial suffix, operating mode and enabled state;
- last provider sync/health/error, last successful order and disabled/replaced timestamps;
- unique provider/account/environment terminal or POS identity;
- no access token, webhook secret or full hardware credential.

### Extend `payment_requests`

- add initiation mode `staff_point` and `staff_qr` alongside `guest_link`;
- staff-initiated requests do not expose a public access token;
- retain the same immutable purpose/amount/currency/calculation snapshot and paid linkage from P3-06A.

### Extend `payment_attempts`

- channel: `integrated_terminal` or `qr`;
- terminal/POS, provider order ID/type, order idempotency key and provider external reference;
- provider order/transaction state and status detail;
- queued/at-terminal/action-required/expiry/cancel timestamps;
- QR mode and checksum of provider `qr_data`; do not retain QR data after operational expiry unless required for audit and encrypted retention is approved;
- unique provider order and transaction identities;
- one active attempt per request and one active order per terminal enforced with PostgreSQL constraints plus locks.

### Reuse, do not fork

- `provider_events`, `provider_refunds` and `settlement_entries` from P3-06A;
- `PaymentService` provider-only exact-once entry point;
- existing Deposit/Folio/RequestRefund/CompleteRefund/document services;
- P3-06B truthful `channel`, `entry_mode` and reports.

### Provider interfaces

Do not add Point/QR methods to controllers. Add a dedicated capability interface implemented by the existing Mercado Pago transport:

```php
interface InPersonPaymentGateway
{
    public function listTerminals(ProviderConnection $connection): array;
    public function createPointOrder(InPersonOrderRequest $request): ProviderOrder;
    public function createQrOrder(QrOrderRequest $request): ProviderOrder;
    public function fetchOrder(string $providerOrderId): ProviderOrder;
    public function cancelOrder(ProviderOrderMutation $request): ProviderOrder;
    public function refundOrder(ProviderOrderRefund $request): ProviderRefund;
}
```

`MercadoPagoOrdersGateway` may share authentication, HTTP transport, money parsing and webhook verification with `MercadoPagoCheckoutProGateway`, but channel-specific request/response normalization and capability tests remain separate.

## 5. Commands and event processing

1. `RegisterOrSyncPaymentTerminal`
   - Finance/Admin only;
   - fetch provider terminal/POS identity using server credentials;
   - bind to one property and reject cross-account/duplicate mappings;
   - enabling integrated Point requires confirmed PDV operating mode.
2. `InitiatePointPayment`
   - authorize front-desk user;
   - lock reservation → payment request → terminal → attempt;
   - derive amount and create the local attempt before remote call;
   - send one idempotent Point order and recover ambiguous results by provider lookup;
   - device-busy/already-queued becomes a visible recoverable state.
3. `InitiateQrPayment`
   - same money/request locks;
   - validate configured store/POS and mode;
   - create an idempotent order and render provider `qr_data` with an approved QR library;
   - bind display expiry to provider order expiry and remove it from the active UI immediately at terminal state.
4. `CancelInPersonOrder`
   - lock terminal/POS and attempt;
   - cancel with a stable idempotency key;
   - ambiguous cancellation requires fetch; never create a replacement while the old order may still pay.
5. `ProcessMercadoPagoOrderEvent`
   - reuse raw signature verification and append-only event ledger;
   - fetch the order; normalize order and transaction states;
   - validate account/channel/terminal/reference/money;
   - invoke the provider-only payment application exactly once;
   - release the active-terminal constraint only after authoritative terminal state.
6. `ReconcileInPersonOrder`
   - scheduled and Finance-triggered recovery for webhook loss, timeout and terminal/operator abandonment;
   - use the same validator/application path as event processing.
7. `ExecuteInPersonRefund`
   - begins from the existing authorized refund request;
   - uses the original provider order/payment and stable idempotency key;
   - completes Inn refund only after provider success/recovery.
8. `DisableOrReplaceTerminal`
   - refuse disable while an active order is unresolved unless Finance explicitly drains/cancels it;
   - preserve history and record replacement linkage.

## 6. UX and API

### Reservation/front-desk hub

- `Charge on Point` shows exact amount, currency, purpose and selected terminal; no amount text box.
- terminal state progresses through queued, at terminal, action required, processing and final state with bounded polling.
- `Show QR` produces an accessible, high-contrast QR plus amount, currency and countdown; expiry/cancel removes the code.
- double-click/refresh returns the active attempt rather than creating another order.
- an unknown/timeout state tells staff not to retry on another terminal until reconciliation resolves it.

### Finance/terminal workspace

- terminal/POS registry with connection, property, mode, health, last order and disable/replace controls;
- active order queue and pending-too-long/device-busy/mismatch/late-approval exceptions;
- attempt/event/order/payment/refund/settlement timeline;
- Finance reconcile/cancel/refund actions call commands, never edit provider state.

### API/OpenAPI

Add typed endpoints/resources for terminal list/sync/enable/disable, Point/QR initiation/status/cancel, order reconciliation and in-person refund. Reuse the P3-06A webhook endpoint/topic processing. Every staff mutation is idempotent; status endpoints are read-only and rate limited. QR response schemas never contain secrets or reservation PII.

## 7. Work packages

| WP | Deliverable | Completion proof |
| --- | --- | --- |
| WP-01 | Terminal/POS schema, mapping, policies and health | Duplicate/cross-account/property mappings denied; disable/replace history preserved |
| WP-02 | Orders gateway and Point/QR DTO normalization | Contract fixtures cover create/fetch/cancel/refund, every order state, malformed payload, auth, 409, 429, 5xx and ambiguous timeout |
| WP-03 | Point initiation and active-terminal locking | Exact amount and one active order under HTTP replay/concurrent workers/device-busy recovery |
| WP-04 | Dynamic/static/hybrid QR initiation | Correct mode/store/POS mapping, provider QR checksum/expiry, one payment and safe cancel/replacement |
| WP-05 | Event/reconciliation/payment application | Signed duplicate/reordered/delayed events and polling produce one request/payment/folio/deposit effect |
| WP-06 | Cancel/refund/late-approval handling | Stable idempotency and authoritative recovery; no double charge/refund or premature replacement |
| WP-07 | Front-desk and Finance UX/API/OpenAPI | Role/property allow-deny, accessible terminal/QR states and exception queues pass |
| WP-08 | Virtual Point and QR sandbox UAT | Provider IDs prove approved/declined/cancelled/expired/refunded paths with secrets redacted |
| WP-09 | Real Point hardware UAT | Supervised real low-value card transaction, receipt, refund and settlement reconciliation pass |

## 8. Ordered agent checklist

1. Create the branch only from synchronized `main` after P3-06A and P3-06B merge; record baseline gates.
2. Re-read the current Orders/Point/QR changelogs and API references; freeze fixture versions and document observed provider fields.
3. Add terminal/request/attempt migrations and PostgreSQL active-order constraints with rollback tests.
4. Add models, factories, policies and terminal mapping/health services.
5. Add `InPersonPaymentGateway`, fake gateway and Mercado Pago Orders implementation with contract fixtures.
6. Implement Point initiation/cancel/status/reconciliation and concurrency tests.
7. Implement QR order/render/expiry/cancel/reconciliation and security tests.
8. Reuse provider event/payment/refund application; add Point/QR-specific mismatch and late-approval tests.
9. Add API/OpenAPI, reservation hub and Finance/terminal UI.
10. Add deterministic Playwright with fake provider plus real QR test-buyer and Point virtual-device scripts.
11. Obtain explicit authorization, production merchant credentials and a physical Point device for supervised low-value hardware UAT; redact evidence.
12. Run all release gates and update status documents. If step 11 cannot run, report the slice as implemented/test-mode only, not client-complete card-present processing.

## 9. Required test matrix

### Terminal/order state

- terminal enabled/disabled/replaced, standalone versus PDV mode, wrong property/account and stale device list;
- no device, offline, busy, already queued, action required, declined, abandoned, cancelled, expired and approved;
- two staff clicks, two terminals for one request, two requests for one terminal and worker crash after remote success;
- cancel versus approval, replacement versus late approval and refund versus dispute;
- provider order/payment state disagreement and unknown provider transaction.

### QR

- static, dynamic and hybrid modes; missing/wrong store/POS mapping;
- invalid/oversized QR payload, checksum mismatch, expired QR and screenshot scanned after replacement;
- two scanners, payment after local timeout and cancel/approval race;
- QR page accessibility, phone/tablet/desktop sizing and no PII in QR/order description.

### Money/security

- exact/partial/full outstanding amounts, currency mismatch and ARS-only provider enforcement;
- idempotency key reused with same and different body;
- duplicate/reordered/delayed/invalid-signature events;
- PAN/CVV/track data absent from browser, request, database, queue, log, exception, event and document payloads;
- partial/full refund, timeout after refund success, late chargeback and settlement variance.

### Browser/provider journeys

1. Staff selects the due deposit and Point terminal; the virtual terminal receives one order.
2. Approved simulation produces one payment/deposit/folio effect and receipt; refresh/replay remains one.
3. Decline, cancel, device-busy and timeout recovery do not create a payment.
4. Staff creates a dynamic QR, test buyer scans/pays and Inn settles it once.
5. Finance sees channel/terminal/POS/event/fee/net details and performs a partial refund.
6. Viewer and cross-property sessions are denied.
7. Supervised real device: staff sends a low-value order, guest uses a real card, Inn receives/reconciles it, Finance refunds it and observes the resulting provider settlement/fee record.

## 10. Release gates and completion language

All focused tests, full SQLite, isolated PostgreSQL `inn_test` races, authenticated/public Playwright, provider virtual/sandbox tests, Pint, PHPStan, ESLint, TypeScript, builds, OpenAPI, Docker health, dependency audits and `git diff --check` must pass.

Start only after dependencies merge:

```bash
git switch main
git pull --ff-only
git switch -c codex/p3-06c-mercado-pago-point-qr
```

There are two evidence levels:

- **Implementation/test-mode complete:** virtual Point and real QR test-buyer journeys pass behind a disabled-by-default production flag.
- **Client-complete card-present:** a configured physical terminal, explicit live-test authorization, real low-value payment, refund, receipt and settlement reconciliation pass.

Never collapse those claims. Never use an uncontrolled production card or charge without explicit authorization.
