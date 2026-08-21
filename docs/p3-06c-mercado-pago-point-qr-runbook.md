# P3-06C Mercado Pago Point and QR Orders runbook

Date: 2026-08-20

## Scope and truth boundary

Inn can create and reconcile Mercado Pago Point and QR orders without collecting card data. Staff choose a server-derived reservation/deposit/folio amount and a registered terminal or POS; the provider order, signed event, and authenticated lookup determine the financial result. Browser state, a device screen, a QR scan, or a staff confirmation never posts money.

There are three different completion claims:

1. **Implementation/test mode** proves the software contract, deterministic Point/QR journeys, signed HTTP processing through an ordinary queue worker, exact-once accounting, receipts, settlement, and responsive UI.
2. **QR sandbox** additionally requires a real supported test seller/POS and test buyer to scan/pay, an accepted signed event or authenticated reconciliation, refund, receipt, and replay proof.
3. **Physical Point** additionally requires an authorized production merchant, a real device in PDV mode, a low-value card transaction, refund (including any terminal action), receipt, and settlement observation.

Never describe level 1 as a provider sandbox payment, level 2 as physical Point, or a virtual Point event as integration-quality measurement.

## Connection and activation

Configure one typed integration connection with:

- `type=payment`, `provider=mercado_pago`, `product=orders` (a compatible existing `checkout_pro` connection may expose Orders capabilities too);
- the exact `external_account_id` (seller user), `provider_application_id`, `environment`, property scope, and `configuration.charge_currency`;
- `payment.point_orders` and/or `payment.qr_orders` outbound capability enabled;
- an access-token secret reference in `secret_reference`;
- an Orders HMAC secret reference in `configuration.webhook_secret_reference`;
- a rotated inbound webhook endpoint key and the dashboard notification **Order (Mercado Pago)**.

Do not store token or HMAC values in connection JSON, terminal/POS records, logs, screenshots, or evidence. Provider identities are unique within provider/account/environment, and an existing terminal/POS cannot be silently moved to another property or connection.

Before enabling Point, sync the terminal and verify its provider-reported operating mode is `PDV`. Standalone mode is not integrated Point. Before enabling QR, register the provider store and external POS exactly and choose its supported `static`, `dynamic`, or `hybrid` mode.

## Staff Point and QR flow

1. Open the reservation payment workspace and select **Charge on Point** or **Show QR**.
2. Confirm purpose, exact server-derived amount/currency, and the terminal/POS. There is no free-form charge amount except the separately authorized partial-payment purpose, which is bounded by the authoritative balance.
3. Point progresses through queued, at-terminal, processing, action-required, or a terminal state. QR data is encrypted at rest while active, displayed as an actual high-contrast QR, and erased immediately on a terminal state.
4. If a request is refreshed or double-clicked, reuse the active attempt. Do not switch terminals/POS while the original order might still pay.
5. Money posts only when lookup matches connection application/account/environment, `point|qr` type, external reference, terminal/POS, exact amount/currency, processed order, and exactly one nonempty/unique processed-accredited transaction whose amount and paid amount equal the request. Additional transaction rows are sorted by identity and cannot make selection order-dependent.

A successful order creates one provider-origin Payment, folio effect, optional deposit application, receipt request, and cashless settlement revision. Duplicate/reordered events and concurrent workers must retain one of each.

## Cancellation and replacement

Always fetch before cancel:

- `created`: call `POST /v1/orders/{id}/cancel` with the attempt's stable cancel idempotency identity;
- Point `at_terminal`: do not call API cancel under the conservative supported rule; persist `action_required` and instruct staff to cancel on the physical terminal;
- processed/refunded/final: reconcile provider truth instead of presenting a successful cancellation;
- timeout or repeated cancel: fetch authoritatively using the same local operation identity.

An active order holds its request and target. Replace it only after authoritative state proves the old order non-payable. A late approval after cancellation/expiry/failure becomes a Finance mismatch and is not applied to a replacement request.

Live documentation check on 2026-08-20 found a Point cancellation discrepancy: the payment-processing and state pages continue to describe `at_terminal` cancellation at the terminal, while the current migration page documents opt-in API cancellation with `x-allow-cancelable-status: at_terminal`. Inn does not send that opt-in header until a real authorized device/account test certifies the resulting race and terminal behavior; the conservative terminal-side rule above remains authoritative for this release.

## Action-required and mismatch queues

`action_required` is sticky operational state, not an automatic retry. Staff checks the Point device; Finance reconciles after the required terminal action. An older `created` or `at_terminal` observation cannot clear it.

Application, seller account, environment, channel, reference, terminal/POS, amount, currency, order ID, transaction identity, transaction amount/paid amount, or transaction-status disagreement becomes `mismatched`. Do not edit the identity or apply money manually. Preserve the raw signed-event ledger and run authoritative reconciliation after correcting the connection/device mapping.

QR buyer rejection is not represented as a failed order in the current Orders model. Leave the QR order created until another successful scan, cancellation, or expiry; never invent a declined QR order.

## Refunds

Point and QR refunds use `POST /v1/orders/{order_id}/refund`, never the Checkout Pro Payments refund route. The refund retains the original provider order, transaction, account, environment, channel, and stable refund idempotency identity. Inn completes the local refund only after authoritative provider success; `processing` or provider/terminal action remains open for reconciliation.

The implementation rejects Point attempts older than 90 days and QR attempts older than 360 days before provider mutation. On 2026-08-20, live official QR pages disagreed: the processing narrative described 180 days, while the Orders migration/API error material described 360 days. Inn therefore treats the provider response as authoritative inside the local 360-day ceiling, persists its reason, and never bypasses a provider denial. Re-read the live pages before regional certification.

## Reconciliation and ordinary workers

The webhook endpoint accepts the singular topic `order`. It verifies the HMAC manifest using lowercased query `data.id`, `x-request-id`, and signature timestamp, then requires body/query resource equality plus exact body `application_id`, `user_id`, and `live_mode` agreement with the canonical connection before appending/dispatching. `payment` continues to use the Checkout Pro processor. Invalid, missing, stale, wrong-topic, or wrong-identity deliveries are rejected.

Run queued events with the normal worker queue list, including `provider-events` and `documents`. Scheduled `payments:reconcile-in-person --older-than=2 --limit=100` uses overlap protection and one-server execution. Finance can reconcile an individual attempt through the typed action/API; it must not change provider state by editing database fields.

Scheduled `payments:expire-in-person-orders` runs every minute with the same overlap/one-server protections. At the exact `order_expires_at` instant it terminalizes an unresolved QR attempt, expires its processing request, and nulls encrypted QR ciphertext; API and Filament suppress the QR at or after that instant even before the scheduler claims the row.

## Deterministic and provider UAT

For the isolated local Compose stack, set non-provider fixture values `MP_COMPOSE_UAT_TOKEN` and `MP_COMPOSE_UAT_WEBHOOK_SECRET`, migrate/seed, then run:

```bash
docker compose -p inn_agent02 exec -T api php artisan payments:in-person-compose-uat --channel=point
docker compose -p inn_agent02 exec -T api php artisan payments:in-person-compose-uat --channel=qr
```

The Playwright `p3-06c-point-qr-orders.spec.ts` journey prepares the handoff, submits a signed HTTP event, waits for the ordinary worker, verifies receipt/settlement exactness, and checks QR appearance/removal at phone, tablet, and desktop widths.

The provider `/v1/orders/{id}/events` endpoint exists only in the console command `payments:point-virtual-uat`; there is no production HTTP route. It requires local/testing, `MP_POINT_UAT_AUTHORIZED=1`, an explicitly authorized compatible test account, and a test terminal ID. Run one documented state at a time and preserve only redacted IDs/state results.

Do not attempt real QR or physical Point with an account whose site/country/currency or merchant capabilities do not support the requested product. Stop at the exact external gate and record it.

## Incident handling

- Busy/offline/already queued: recover the existing provider order when its identity is returned; do not blind-retry create.
- Ambiguous create timeout: retain the attempt in `creating`; retrying the same Inn command resumes the same attempt and reissues the exact body with the same provider idempotency key. Never strand an identity-less attempt in `action_required`.
- Ambiguous cancel/refund timeout: retain the operation and reconcile by lookup with the same stable operation identity.
- Signed event accepted but no accounting result: inspect the immutable event state, worker logs, connection identity, and mismatch queue; replay safely after the cause is fixed.
- QR still visible after terminal state: remove it immediately, verify ciphertext is null and only checksum/provider IDs remain, then investigate cache/browser state.
- Suspected PAN/CVV/PIN/track/chip/NFC material: stop, restrict access, follow the payment-data incident process, and do not copy the value into a ticket or screenshot.

## Primary provider references

- [Point payment processing](https://www.mercadopago.com.ar/developers/es/docs/mp-point/payment-processing)
- [Point terminal-list response (`data.terminals`)](https://www.mercadopago.com.ar/developers/es/reference/in-person-payments/point/terminals/get-terminals/get)
- [Point virtual-device test](https://www.mercadopago.com.ar/developers/es/docs/mp-point/integration-test)
- [Point order and transaction states](https://www.mercadopago.com.ar/developers/es/docs/mp-point/resources/status-order-transaction)
- [Point Orders migration/refunds and opt-in `at_terminal` cancellation](https://www.mercadopago.com.ar/developers/es/docs/mp-point/migrate-payment-intent-to-orders)
- [Orders webhook signatures](https://www.mercadopago.com.ar/developers/es/docs/checkout-api-orders/notifications)
- [QR processing](https://www.mercadopago.com.ar/developers/es/docs/qr-code/payment-processing)
- [QR test purchase](https://www.mercadopago.com.ar/developers/es/docs/qr-code/test-integration/test-purchase)
- [QR Orders migration](https://www.mercadopago.com.ar/developers/es/docs/qr-code/migrate-dynamic-qr-model-to-orders)
- [QR refund errors](https://www.mercadopago.com.ar/developers/es/docs/qr-code/resources/refund-errors)
