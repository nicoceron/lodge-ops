# Agent 02 — P3-06C Mercado Pago Point and QR Orders

## Copy/paste assignment

> Implement P3-06C only after P3-06B is merged and `main` is synchronized. Read this file, the coordinator README, the complete P3-06C plan, P3-06A/B plans and runbooks, and current Mercado Pago Orders/Point/QR documentation. Build a separate Orders gateway, topic-aware signed event processor, Point terminal and QR POS registries, exact-once payment/refund application, staff UI, contract/concurrency tests, virtual Point UAT and real QR sandbox UAT. Do not claim physical Point completion without an authorized production merchant device and a real low-value card/refund journey.

## Branch, dependency, and allowed pre-work

- Branch: `codex/p3-06c-mercado-pago-point-qr` from `main` after Agent 01.
- Before Agent 01 merges, only documentation snapshots, DTOs, redacted fixtures, a fake Orders gateway, and isolated contract tests may be prepared in a **disposable prework branch/worktree**. After Agent 01, create the named P3-06C branch from synchronized `main` and cherry-pick only reviewed prework commits. Do not edit migrations, `PaymentService`, UI, routes, or master docs early.
- Own Point/QR Orders endpoints, device/POS registry, staff initiation, order events, order refunds, UI/tests/runbook.
- Reuse signed envelope verification, event ledger, financial lock order, receipts and settlements without forcing Orders resources through Checkout Pro payment endpoints.

## Required data and compatibility fixes

- Make public-link token/hash nullable for staff-initiated `staff_point` and `staff_qr` requests; constrain token presence by initiation mode.
- Add `payment_terminals` for Point hardware and `provider_pos_locations` for QR store/POS identity and `dynamic|static|hybrid` capability.
- Extend attempts with channel, terminal/POS, provider order/transaction IDs, order type, QR mode, encrypted temporary QR data, persistent checksum, operation idempotency identities, and normalized order/transaction states.
- Extend refunds with provider resource type, order ID, transaction ID; backfill Checkout Pro as payment resources.
- Include integration connection/account/environment in provider identities and uniqueness.
- Enforce one active order per request and per Point terminal; for static/hybrid QR, one active order per POS. Include queued, at-terminal, action-required and processing states.
- Null encrypted QR payload on terminal state; retain only checksum/provider IDs.

## Provider boundary and processing

- Create `MercadoPagoOrdersGateway`: list/sync terminals, read operating mode, create Point/QR order, fetch order, cancel order, refund order, normalize order/transaction/refund state, verify shared HMAC envelope.
- Add topic-aware webhook dispatch: `payment` to Checkout Pro processor and `order` to Orders processor. Subscribe to dashboard event **Order (Mercado Pago)**, whose event/query/body topic is `order`, not legacy Point/IPN topics.
- Refactor `PaymentService` to accept a trusted server-created provider-application DTO; remove its Checkout Pro method hardcode while preserving all earlier tests.
- Use `/v1/orders/{order_id}/refund` for Point/QR refunds. Do not route them through `/v1/payments/{id}/refunds`.
- Stable operation-specific `X-Idempotency-Key` for create/cancel/refund; same key/different request checksum fails locally.
- Never expose the provider test `/events` simulation endpoint in production code. Keep it in an environment-guarded UAT harness.
- Apply money only after authenticated lookup proves account/environment, `type=point|qr`, external reference, terminal/POS identity, exact amount/currency, processed order, processed/accredited transaction and unique transaction identity.

## State-model rules

- Point virtual testing may use a complete virtual terminal ID such as `NEWLAND_N950__SBX0000001`; wait/poll according to official asynchronous behavior.
- `action_required` is operational, not auto-transient. Send it to an operator/Finance queue.
- Recover `already_queued_order_for_terminal` by fetching the existing order rather than blind retry.
- QR does not expose rejected payments: a failed scan leaves the order created until success, cancel or expiry. Do not invent a declined order state.
- Model late approval after cancellation/replacement as provider truth plus Finance reconciliation; never apply it to a new request.
- Some Point refunds require card/terminal action. Persist provider-action-required and reconcile to completion.
- Enforce provider refund eligibility using authoritative order dates/statuses: current Point guidance is generally 90 days and current QR Orders guidance is 360 days. Re-read the live provider pages on implementation day, store the returned reason, and never bypass a changed provider limit.
- Fetch authoritatively before cancel. API cancellation applies only while the order is `created`; Point `at_terminal` requires terminal-side cancellation. Persist terminal-cancel-needed/action-required, do not replace the order until lookup proves it non-payable, and recover timeout/repeated cancel through lookup.

## Required UAT and tests

- Fixtures for every documented state, malformed response, 400/401/403/409/425/428/429/5xx, retry-after, and timeout after remote success.
- Concurrent clicks/workers, two terminals for one request, two requests for one terminal, cancel versus approval, replacement versus late approval.
- Signed duplicate/reordered/delayed `order` events, account/device/POS/amount/currency mismatch, disabled/replaced device.
- Point virtual `/events` simulation: processed, failed, canceled, expired, action-required and refunded. Cover offline, busy and already-queued as deterministic gateway/API contract cases unless the current official sandbox exposes a supported real simulation.
- QR sandbox: actual test buyer scans/pays, signed order event is persisted, lookup applies once, refresh/replay stays exact, cancel and partial/full refund reconcile within the provider eligibility window.
- Point refund tests include the 90-day boundary and physical-card/terminal-required recovery; QR refund tests include the currently documented 360-day boundary.
- Cancellation tests cover created versus at-terminal, terminal cancel required, cancel timeout then lookup, repeat cancel and cancel-versus-approval.
- QR failed scan remains locally unpaid/open.
- Role/property/IDOR; phone/tablet/desktop QR expiration/removal; no PII in external reference/description/QR.
- Three completion levels in evidence:
  1. implementation/test mode: contracts + Point virtual + QR test harness;
  2. QR sandbox: real test buyer, signed event/lookup, refund, receipt;
  3. client-complete Point: production merchant, physical device in PDV mode, authorized low-value card, receipt, refund and settlement.
- Mercado Pago states test accounts cannot make real physical Point payments and its standard virtual device is not valid for integration-quality measurement. Only level 3 proves hardware/card-present behavior.
- Run universal gates plus full payment regression and ordinary worker processing.

## Primary references

- [Existing P3-06C plan](../p3-06c-mercado-pago-point-qr-implementation-plan.md)
- [Point processing](https://www.mercadopago.com.ar/developers/es/docs/mp-point/payment-processing)
- [Point virtual-device test](https://www.mercadopago.com.ar/developers/es/docs/mp-point/integration-test)
- [Point order/transaction states](https://www.mercadopago.com.ar/developers/es/docs/mp-point/resources/status-order-transaction)
- [Point Orders migration/refunds](https://www.mercadopago.com.ar/developers/es/docs/mp-point-v2/migrate-payment-intent-to-orders)
- [Point terminal configuration](https://www.mercadopago.com.ar/developers/es/docs/mp-point/configure-terminal)
- [Point create order API](https://www.mercadopago.com.ar/developers/es/reference/in-person-payments/point/orders/create-order/post), [cancel API](https://www.mercadopago.com.ar/developers/es/reference/in-person-payments/point/orders/cancel-order/post), and [refund API](https://www.mercadopago.com.ar/developers/es/reference/in-person-payments/point/orders/refund-order/post)
- [Orders webhook signatures](https://www.mercadopago.com.ar/developers/es/docs/checkout-api-orders/notifications)
- [QR processing](https://www.mercadopago.com.ar/developers/es/docs/qr-code/payment-processing)
- [QR test purchase](https://www.mercadopago.com.ar/developers/es/docs/qr-code/test-integration/test-purchase)
- [QR Orders migration](https://www.mercadopago.com.ar/developers/es/docs/qr-code/migrate-dynamic-qr-model-to-orders)
- [QR create order API](https://www.mercadopago.com.ar/developers/es/reference/in-person-payments/qr-code/orders/create-order/post), [cancel API](https://www.mercadopago.com.ar/developers/es/reference/in-person-payments/qr-code/orders/cancel-order/post), [refund API](https://www.mercadopago.com.ar/developers/es/reference/in-person-payments/qr-code/orders/refund-order/post), and [refund errors](https://www.mercadopago.com.ar/developers/es/docs/qr-code/resources/refund-errors). Current QR narrative/API pages disagree on 180 versus 360 days; record the live-doc/API result during implementation.
