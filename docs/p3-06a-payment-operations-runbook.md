# P3-06A payment operations runbook

Status: deterministic implementation available; Mercado Pago test-mode acceptance remains pending credentials and an HTTPS webhook endpoint.

## Connection setup

Create one tenant integration connection with `type=payment`, `name=mercado-pago-argentina`, and only non-secret configuration:

```json
{
  "provider": "mercado_pago",
  "environment": "sandbox",
  "site": "MLA",
  "charge_currency": "ARS",
  "provider_account": "TEST-SELLER-ID",
  "return_url_base": "https://public-https-host.example",
  "webhook_key": "GENERATE-AT-LEAST-32-RANDOM-CHARACTERS",
  "webhook_secret_reference": "env:MERCADO_PAGO_WEBHOOK_SECRET"
}
```

Set `secret_reference=env:MERCADO_PAGO_ACCESS_TOKEN`. Put both values in the runtime secret store/environment, never in the row, logs, screenshots, fixtures, tickets, or this document. Restart API and worker processes after rotation. Old webhook deliveries signed with the retired secret will be rejected; reconcile their known payment attempts after the rotation window.

Register the provider notification URL as `https://<host>/api/v1/payment-webhooks/<webhook_key>`. The webhook route verifies the raw bytes and signature before decoding JSON, persists an encrypted private payload, returns quickly, and queues authoritative provider lookup on `provider-events`.

## Triage and recovery

- Payment attempts: Finance → Payment attempts. `mismatched` never posts money. Confirm seller account, external reference, amount, and currency before changing configuration or replaying anything.
- Provider events: Finance → Provider events. Invalid signatures are not persisted. `failed` is retryable after the cause is understood; `mismatched` requires a recorded investigation.
- Settlement entries: Finance → Settlement entries. `variance` means provider gross minus fees does not equal reported net and must remain visible until explained.
- A browser return is never evidence of payment. Do not manually create a provider-origin payment to clear a pending attempt.

Safe recovery commands:

```bash
php artisan payments:reconcile <payment-attempt-uuid>
php artisan payments:replay-event <provider-event-uuid> --reason="provider outage resolved"
php artisan payments:expire-requests --tenant=<tenant-uuid>
```

All paths reuse authoritative provider lookup and the same exactly-once application service. A refund starts as an Inn `refund_requested` change; Finance executes it through the provider endpoint/action. If the HTTP result is ambiguous, retain the stable idempotency key and recover the provider result before allowing `CompleteRefund` to post the folio effect.

## Test-mode acceptance record

Required evidence before calling P3-06A sandbox-complete:

1. Staff issues an Inn URL for an ARS deposit; the URL opens on a 390×844 viewport.
2. Argentina test buyer completes approved, pending, and rejected Checkout Pro scenarios.
3. The signed provider notification and authenticated lookup produce exactly one request/payment/deposit/folio effect across refresh and duplicate delivery.
4. Finance can explain provider payment ID, gross, fee, and net without exposing credentials.
5. A partial provider refund completes exactly one Inn refund and generates the immutable refund receipt.
6. Redacted screenshots/video and provider IDs are attached to the UAT ledger. Never attach test credentials or signatures.

Until those steps are executed with Mercado Pago test credentials, the correct claim is “deterministic fake/contract/PostgreSQL implementation,” not “sandbox-complete” or “production-ready.”
