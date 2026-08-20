# P3-06A payment operations runbook

Status: software-controlled closure is complete, and the release owner accepts the observed Colombia/MCO + COP test-mode journey for the current P3-06A merge. Argentina/MLA + ARS is a deferred regional certification, not a merge blocker. Production-origin signed delivery over the final public HTTPS endpoint remains an unproven production-activation/final-certification gate.

## Connection setup

Create one tenant integration connection per merchant application/account and its declared site/currency capabilities. The currently certified example is Colombia/MCO + COP; names are operator-defined rather than embedded product behavior:

```json
{
  "provider": "mercado_pago",
  "environment": "sandbox",
  "site": "MCO",
  "charge_currency": "COP",
  "provider_account": "TEST-SELLER-ID",
  "return_url_base": "https://public-https-host.example",
  "webhook_key": "GENERATE-AT-LEAST-32-RANDOM-CHARACTERS",
  "webhook_secret_reference": "env:MERCADO_PAGO_WEBHOOK_SECRET"
}
```

Set `secret_reference=env:MERCADO_PAGO_ACCESS_TOKEN`. Put both values in the runtime secret store/environment, never in the row, logs, screenshots, fixtures, tickets, or this document. Restart API and worker processes after rotation. Old webhook deliveries signed with the retired secret will be rejected; reconcile their known payment attempts after the rotation window.

Mercado Pago credentials identify and are directly linked to an application/integration; do not treat an access token as a portable country capability. For a merchant other than the application's own account, use the provider's OAuth seller-authorization flow and persist the resulting connection identity without exposing its credentials. Site/country, currency and enabled payment methods are capabilities of that connection. See Mercado Pago's official [credentials](https://www.mercadopago.com.ar/developers/en/docs/your-integrations/credentials) and [OAuth authorization-code](https://www.mercadopago.com.ar/developers/en/docs/security/oauth/creation) documentation.

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

## Current Colombia/MCO test-mode acceptance record

The release owner accepts this evidence for the current P3-06A merge:

1. Staff issues an Inn URL for a configured COP deposit; the URL opens on a 390×844 viewport.
2. An MCO test buyer completes a real COP 10,000 Checkout Pro approval.
3. A dashboard/test signed notification submitted through the public HTTPS endpoint and the authenticated provider lookup produce exactly one request/payment/deposit/folio effect across refresh and duplicate delivery. This does not claim that Mercado Pago originated the HTTP delivery.
4. Finance can explain provider payment ID, gross, fee, and net without exposing credentials.
5. A COP 2,000 partial provider refund completed in the seller UI is recovered authoritatively into exactly one Inn refund and generates the immutable refund receipt.
6. Import Account Money and Released Money reports where the merchant account provides them; otherwise preserve the deterministic fixture proof and record the production-report activation gate.
7. Redacted screenshots/video and provider IDs are attached to the UAT ledger. Never attach test credentials or signatures.

The MCO account's hosted pending/rejected cards and direct refund API were restricted by provider policy. Those limits are recorded evidence about this connection, not current P3-06A merge blockers; deterministic contract/browser suites cover the software-controlled state paths.

## Deferred Argentina/ARS regional certification

An Argentina deployment still requires a separately authorized MLA seller application/account connection with ARS capability. It must preserve the existing ARS and USD→ARS invariants and repeat the operational journey with an Argentina test buyer: approved/pending/rejected outcomes, authoritative event lookup, duplicate protection, provider refund/recovery, receipts, and report/variance review. An MCO token, MLA test-user identifier, or ARS preference created under the Colombia application must not be relabeled as proof of an MLA merchant connection.

## Production activation/final certification

Before production activation, configure the final merchant application/account, secrets, enabled country/currency/payment-method capabilities, return origins and public HTTPS notification URL. Observe a Mercado Pago-originated delivery whose provider signature manifest validates at that final endpoint, then prove authoritative lookup, ordinary-worker consumption, exactly-once accounting and operational replay/recovery. The current dashboard/test HMAC delivery proves the application path but is not production-origin delivery evidence; neither the current MCO/COP release decision nor future MLA/ARS certification should be described as production-ready without this gate.
