# P3-06A payment operations runbook

Status: software-controlled closure available; Colombia/MCO evidence is preserved; same-country Argentina/ARS provider acceptance remains pending an MLA seller application/access token and public HTTPS activation.

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

Register the provider notification URL as `https://<host>/api/v1/payment-webhooks/<webhook_key>`. The webhook route verifies the provider signature manifest before decoding the exact raw body, persists an encrypted private payload and its checksum, returns quickly, and queues authoritative provider lookup on `provider-events`.

The canonical webhook key is globally unique across all tenants. Configuration writes synchronize it to the indexed connection column so a public request cannot ambiguously resolve to another tenant. The normal worker must include `provider-events`; do not run a one-off worker as acceptance evidence.

## Triage and recovery

- Payment attempts: Finance → Payment attempts. `mismatched` never posts money. Confirm seller account, external reference, amount, and currency before changing configuration or replaying anything.
- Provider events: Finance → Provider events. Invalid signatures are not persisted. `failed` is retryable after the cause is understood; `mismatched` requires a recorded investigation.
- Provider refunds: Finance → Provider refunds. Recover a provider-dashboard refund or ambiguous execution through the authorized recovery action; never insert a completed Inn refund manually.
- Provider disputes: Finance → Provider disputes. Chargeback notifications are retrieved authoritatively, preserve immutable revisions, and can only post the remaining reversible amount. Mercado Pago `claim` topics are retained as unsupported Finance work in this slice; they are not sent to the chargebacks endpoint.
- Settlement entries: Finance → Settlement entries. `variance` remains visible until an authorized operator records an investigation/resolution note. A payment-resource lookup is not payout evidence.
- Settlement report exceptions: Finance → Settlement report exceptions. Unknown or mismatched account/resource/external-reference/currency/amount rows stay unapplied and expose only allow-listed reconciliation fields. Property-scoped Finance sees only rows matched to that property; tenant-wide unknown rows require tenant-wide Finance scope.
- A browser return is never evidence of payment. Do not manually create a provider-origin payment to clear a pending attempt.

Safe recovery commands:

```bash
php artisan payments:reconcile <payment-attempt-uuid>
php artisan payments:replay-event <provider-event-uuid> --reason="provider outage resolved"
php artisan payments:expire-requests --tenant=<tenant-uuid>
php artisan payments:recover-refund <provider-refund-uuid> <provider-refund-id>
php artisan payments:recover-refunds
php artisan payments:import-settlement-report <connection-uuid> <csv-path> --report=account_money --provider-report-id=<provider-report-id>
php artisan payments:import-settlement-report <connection-uuid> <csv-path> --report=released_money --provider-report-id=<provider-report-id>
```

All paths reuse authoritative provider lookup and the same exactly-once application service. A refund starts as an Inn `refund_requested` change; Finance executes it through the provider endpoint/action. If the HTTP result is ambiguous, retain the stable idempotency key and recover the provider result before allowing `CompleteRefund` to post the folio effect.

`payments:expire-requests` runs every minute with a named overlap lock and `onOneServer()`. `payments:recover-refunds` runs on the same protected scheduler topology. Keep scheduler cache shared between nodes and monitor overlap-lock expiry/crash-retry alerts.

## Settlement report handling

- Accept only official UTF-8 Account Money or Released Money CSV exports. The parser is BOM tolerant and supports the provider's comma/semicolon delimiters.
- The import envelope stores the exact file SHA-256 and provider report identity/revision. An identical replay is a no-op; changed bytes under the same report ID create an immutable revision and visible variance.
- Account Money `SETTLEMENT_DATE` is payment approval and `MONEY_RELEASE_DATE` is expected release. Released Money `DATE` is actual balance impact; classify by `RECORD_TYPE` before free-text `DESCRIPTION`.
- Released Money movement columns are `NET_CREDIT_AMOUNT` and `NET_DEBIT_AMOUNT`. Do not rename them or infer missing facts.
- Only required financial/correlation fields enter the canonical row ledger. Payer names, email, document, address and other guest fields are discarded rather than logged or retained.
- Account-level payout/withdrawal and balance-availability rows remain account-level. Never attach them to a payment using report proximity or external reference alone.
- Use `--fixture` only for committed synthetic deterministic fixtures. Test accounts may return empty reports; a production merchant report remains a separate payout/withholding activation gate.

## Test-mode acceptance record

Required evidence before calling P3-06A sandbox-complete:

1. Staff issues an Inn URL for an ARS deposit; the URL opens on a 390×844 viewport.
2. Argentina test buyer completes approved, pending, and rejected Checkout Pro scenarios.
3. The signed provider notification and authenticated lookup produce exactly one request/payment/deposit/folio effect across refresh and duplicate delivery.
4. Finance can explain provider payment ID, gross, fee, and net without exposing credentials.
5. A partial provider refund completes exactly one Inn refund and generates the immutable refund receipt.
6. Import Account Money and Released Money reports where the merchant account provides them; otherwise preserve the deterministic fixture proof and record the production-report activation gate.
7. Redacted screenshots/video and provider IDs are attached to the UAT ledger. Never attach test credentials or signatures.

The existing authorized MCO account could create MLA test identities, but Mercado Pago did not issue an MLA seller access token and an ARS preference remained bound to the Colombia host. Until a same-country MLA seller application/access token completes the required journey, the correct claim is “software-controlled closure plus MCO/COP provider evidence,” not “Argentina sandbox-complete” or “production-ready.”
