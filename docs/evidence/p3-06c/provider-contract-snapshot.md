# P3-06C Mercado Pago Orders contract snapshot

Snapshot date: 2026-08-20

Scope: implementation-day live documentation and deterministic contract baseline

Evidence level: documentation, production Orders adapter, fake/deterministic gateway, redacted fixtures, and implementation tests

This snapshot does not claim a real QR sandbox purchase, an authorized Point virtual-device provider run, physical hardware behavior, or production readiness. The Orders adapter and application flow exist, but the recorded provider credential is an MCO/Colombia test account that cannot establish the requested MLA/Argentina + ARS Point/QR evidence.

## Implemented contract

- Point and QR share the provider `/v1/orders` resource but remain distinct channels in Inn.
- Every create, cancel, and refund mutation carries an operation-specific `X-Idempotency-Key`. Inn must persist a canonical request checksum and reject the same key with a different checksum before any provider call.
- Point creation identifies a complete terminal ID such as the provider's documented virtual `NEWLAND_N950__SBX0000001`. QR creation identifies an external POS and one of `static`, `dynamic`, or `hybrid`.
- Dynamic and hybrid QR responses return `type_response.qr_data`; static QR does not. Inn encrypts temporary QR data, persists a checksum, renders the active QR through a maintained QR library, and erases ciphertext at terminal state.
- Authoritative lookup returns an order plus payment/refund transactions. The provider account (`user_id`), order type, external reference, terminal/POS, amount, currency, processed order state, processed/accredited transaction, and unique transaction identity all remain required before money application.
- Point and QR refunds use `POST /v1/orders/{order_id}/refund`, never Checkout Pro's Payments refund endpoint. Full refunds have no body; partial refunds identify the order transaction and amount.

## State and recovery observations

Point's current documented order states are `created`, `at_terminal`, `processed`, `failed`, `canceled`, `expired`, `action_required`, and `refunded`. `action_required` is not transient: the provider state page says it will not change and instructs the operator to check the terminal. The fixture therefore leaves it in a Finance/operator state rather than treating it as an automatic retry.

The QR Orders migration explicitly says rejected scans are not exposed as a rejected order. A failed buyer attempt leaves the order `created` for another attempt, cancellation, or expiry. The QR fixture set deliberately contains no invented `failed` order.

Current provider pages conflict on Point cancellation at `at_terminal`: the primary processing page and assignment require terminal-side cancellation, while a migration page describes an `x-allow-cancelable-status: at_terminal` API option. The later implementation must fetch first and use the conservative assignment rule—API cancel only for authoritative `created`; persist terminal-cancel-needed/action-required for `at_terminal`—unless the provider confirms a supported account/device flow during implementation-day revalidation.

Ambiguous create, cancel, or refund timeouts recover through authoritative order lookup using the same stable local operation identity. `already_queued_order_for_terminal` is a recovery signal to locate/fetch the existing order, not permission to send a blind second create.

## Notification identity

The dashboard selection is labelled **Order (Mercado Pago)**. Current webhook examples send query `type=order`, body `type=order`, and actions such as `order.processed`. Some migration prose calls the dashboard subscription topic `orders`; code dispatch must use the delivered `order` identity and must not revive legacy `point_integration_wh`, `payments`, or `merchant_orders` topics for Orders resources.

Orders HMAC verification uses the lowercased `data.id` query value, `x-request-id`, and signature timestamp in the manifest. The implementation rejects missing, invalid, stale, malformed, and wrong-topic envelopes before dispatch, appends authenticated duplicates, and routes singular `order` events to the Orders processor while retaining `payment` for Checkout Pro.

## Refund windows and asynchronous outcomes

- Current Point processing/migration guidance states 90 days from payment. Some Point refunds require card/terminal action.
- Live pages on 2026-08-20 do not resolve QR consistently: the QR processing narrative says 180 days, while Orders migration/API error material says 360 days. Inn enforces a local 360-day maximum, calls the provider authoritatively inside that ceiling, and persists the returned denial reason instead of bypassing a stricter account/API rule. The test matrix proves the exact 360/361 boundary but does not claim provider acceptance at day 360.
- A total refund may return `processing`; partial refund commonly returns `processed`. Provider-action-required and pending refund states must reconcile to an authoritative terminal result before Inn completes its refund.

## Fixture inventory

`apps/api/tests/Fixtures/MercadoPago/Orders` contains synthetic, redacted examples for every documented Point state; QR static/dynamic/hybrid creation, processing, and refund; `order` webhook payloads; malformed JSON; the required 400/401/403/409/425/428/429/5xx classes; `Retry-After`; and timeout-after-remote-success recovery. The fixture IDs, account aliases, QR payloads, messages, and references are synthetic and are not provider evidence. Contract tests additionally assert the current create payload's top-level `total_amount` and `description`, payment amount, stable mutation idempotency headers, and Orders-only refund path.

## Hardware and sandbox boundary

Mercado Pago states that test accounts cannot make real physical Point payments. The standard virtual device supports deterministic status simulation, may take up to 10 seconds (40 seconds for `action_required`), and is not valid for integration-quality measurement. Physical Point remains unproven until an authorized production merchant device in PDV mode completes a real low-value card payment, receipt, refund, and settlement/reconciliation journey.

The available authorized credential was checked read-only through Mercado Pago `/users/me`: it identifies an MCO/Colombia test account. Mercado Pago's current product-availability material does not establish the requested Point/QR Orders path for that country/account, and the assignment requires Argentina/ARS. No terminal, POS, order, QR purchase, event simulation, or refund mutation was attempted with that incompatible connection. Real QR sandbox and provider Point virtual evidence therefore remain an exact account/country/capability gate rather than an implementation failure.

## Primary sources read for this snapshot

- Mercado Pago Point processing, terminal configuration, virtual-device tests, order/transaction states, Orders migration, create/cancel/refund API references, and Orders notifications.
- Mercado Pago QR overview, processing, test purchase, Orders migration, create/cancel/refund API references, and refund errors.
- The P3-06A payment plan/runbook and P3-06B/P3-06C implementation plans in this repository.
