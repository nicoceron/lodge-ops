# Agent 00 — P3-06A Mercado Pago provider closure

## Copy/paste assignment

> Close P3-06A honestly on the existing `codex/p3-06-payment-gateway-mercado-pago` branch. Read this file, `docs/agent-plans/README.md`, the full P3-06A implementation plan and operations runbook, Rincón Grande requirements, and the current PR #8 diff before editing. Preserve all approved MCO evidence, correct false/stale status language, fix every source/runtime gap below, and run the normal worker/scheduler/provider journeys. Do not start P3-06B. Keep PR #8 draft until every software-controlled gate passes and the documented Argentina/ARS acceptance gate passes or the release owner explicitly changes that criterion. Never store credentials or raw payment data.

## Base, scope, and exclusions

- Branch: existing `codex/p3-06-payment-gateway-mercado-pago`; do not create or stack another feature branch.
- Work on the existing branch and PR #8. Verify its base remains `e459935` or later and inspect new review/CI state first.
- Own only Checkout Pro payment requests, events, refund recovery, disputes, settlement reconciliation, queue/scheduler wiring, docs, and tests.
- Do not add cash shifts, standalone terminal tenders, Point, QR, direct booking, or a second PSP.
- Read completely:
  - `docs/p3-06-mercado-pago-payments-implementation-plan.md`
  - `docs/p3-06a-payment-operations-runbook.md`
  - `docs/client-uat-ledger.md`
  - `apps/api/app/Services/Payments/*`
  - `apps/api/app/Integrations/Payments/MercadoPago/*`
  - payment migrations/models/jobs/controllers/resources/policies/tests
  - `compose.yml` and `apps/api/routes/console.php`

## Mandatory corrections before merge

1. Add `provider-events` to the normal Compose/production worker topology. Prove a webhook accepted by the HTTP endpoint is consumed by the normal worker, not by manually invoking the job.
2. Schedule `payments:expire-requests` with named overlap protection and `onOneServer()`. Prove expired links/attempts become unusable exactly once.
3. Add an authorized, idempotent refund-recovery command/action that calls `PaymentGateway::fetchRefund()`, reconciles provider-dashboard refunds, and records one Inn refund/folio effect. Include scheduled/manual recovery for stuck `processing` refunds.
4. Stop collapsing `charged_back` and `refunded` payments into a generic mismatch. Add an append-only dispute/chargeback lifecycle, authoritative lookup, Finance queue/action, reservation/payment/folio impact policy, and audit trail. Never silently reverse money from an unauthenticated event.
5. Complete settlement truth. Persist gross, fee, nullable tax/withholding/refunded/chargeback/net and nullable settlement/payout identity/date/status with immutable revisions. Add Finance variance investigation/resolution with notes and actor; never mutate away prior provider facts. Test accounts do not populate real Account Money/Released Money reports, so sandbox proves provider fields actually returned plus deterministic report-import fixtures; a production merchant report is the separate payout/withholding activation gate.
6. Make provider-account/environment identity part of all uniqueness and mismatch checks. Unknown account, currency, amount, external reference, or resource ID must remain unapplied and visible for Finance.
7. Correct the docs: MCO COP approval is provider evidence, not the ARS release. A Checkout Pro return is informational. Dashboard webhook simulation plus authenticated resource lookup is the documented test-notification path; do not require a spontaneous sandbox delivery that the provider does not promise. Production still requires a real signed delivery through public HTTPS.
8. Preserve the API-refund policy-denial fallback: provider UI refund followed by authoritative recovery is a supported operational path. Automated refund activation remains a merchant-account capability gate, not a code failure.

## Financial and concurrency invariants

- Follow the established reservation → payment/deposit → refund/settlement lock order.
- An approved provider payment applies exactly one `payments` row, deposit satisfaction, folio payment line, receipt request, and settlement revision.
- Duplicate, delayed, reordered, or replayed events and browser refreshes never duplicate accounting.
- A stale/superseded checkout can record provider truth but cannot attach money to the wrong request/reservation; route it to review/refund.
- A chargeback/refund arriving after checkout, cancellation, folio close, or another refund preserves all earlier history and computes only the remaining reversible amount.
- Same idempotency key/different payload fails before any provider mutation.
- Provider calls happen outside long database locks where possible; uncertain timeouts reconcile before retrying a mutation.

## Required tests

- Unit/contract fixtures for approved, pending, rejected, canceled, refunded, partially refunded, charged back, unknown, malformed, and mismatched resources.
- HTTP signature: valid raw body, bad/missing/stale signature, duplicate event, unknown account/resource, and throttling.
- PostgreSQL races: duplicate webhook workers, manual reconcile versus webhook, refund execute versus recover, refund versus chargeback, settlement revision replay, checkout expiry versus approval.
- Scheduler tests: one occurrence across two scheduler nodes, overlap-lock expiry, crash/retry, boundary instant.
- Policy/property/IDOR tests for payment request, attempt, refund, dispute, settlement, and private receipts.
- Compose UAT: HTTP webhook → queued `provider-events` job → authoritative lookup → exactly-once accounting using the normal configured worker.
- Browser UAT: issue → mobile guest link → hosted checkout/pending return → authoritative reconcile → receipt → partial refund/recovery → refund receipt → Finance settlement/variance review using sandbox facts and clearly labeled report fixtures.
- Chargeback evidence in test mode uses the supported signed topic/dashboard simulation where available, authoritative lookup fixtures/contract tests and an unapplied Finance workflow. A real dispute and final resolution is production evidence, not a fabricated sandbox gate.

## Provider acceptance run

- Use same-country Argentina test seller/buyer, ARS, test credentials, incognito buyer session, and public HTTPS callback.
- Exercise approved and every provider-supported pending/rejected scenario. Record provider limitations rather than fabricating states.
- Send a signed dashboard simulation to the real public endpoint and prove worker processing plus authenticated lookup. If a real provider delivery is available in production mode, record it separately.
- Exercise API partial refund with stable idempotency. If policy-denied, perform the refund in provider UI and use the new Inn recovery command.
- Download/open both receipts and reconcile amount, currency, payment ID, refund ID, gross, fees and every field actually returned in sandbox. Do not synthesize payout IDs, payout dates or withholdings to make a settlement test pass.
- Revoke/expire all UAT links and retain only redacted IDs/checksums in evidence.

## Completion gate and handoff

- Run the universal gates, both SQLite and PostgreSQL suites, focused payment contract/concurrency tests, Docker smoke, authenticated/public Chromium, audits, secret scan, and `git diff --check`.
- Update the P3-06A plan/runbook/evidence truth, but leave the master status documents for coordinator finalization.
- PR description must state country/currency tested, which notification mechanism was proved, refund path used, queue/scheduler evidence, test counts, skipped tests, provider restrictions, and zero retained secrets.
- Do not call the slice complete while the ordinary worker cannot consume the event, expiry is unscheduled, refund recovery is manual SQL/code, chargebacks collapse to mismatch, or settlement variance has no workflow.

## Primary references

- [Checkout Pro test integration](https://www.mercadopago.com.ar/developers/es/docs/checkout-pro/integration-test)
- [Checkout Pro test purchases](https://www.mercadopago.com.ar/developers/es/docs/checkout-pro/integration-test/test-purchases)
- [Checkout Pro payment notifications](https://www.mercadopago.com.ar/developers/es/docs/checkout-pro/payment-notifications)
- [Checkout Pro refund API](https://www.mercadopago.com.ar/developers/es/reference/online-payments/checkout-pro/create-refund/post)
- [Mercado Pago notification topics](https://www.mercadopago.com.ar/developers/en/docs/your-integrations/notifications)
- [Account Money reports](https://www.mercadopago.com.ar/developers/es/docs/checkout-pro/additional-content/reports/account-money/introduction) and [Released Money reports](https://www.mercadopago.com.ar/developers/es/docs/reports/released-money/introduction)
- [Chargeback management](https://www.mercadopago.com.ar/developers/es/docs/checkout-pro/chargebacks/manage) and [notification details](https://www.mercadopago.com.ar/developers/es/docs/checkout-pro/additional-content/notifications/additional-info)
- [Laravel queues](https://laravel.com/docs/13.x/queues), [scheduling](https://laravel.com/docs/13.x/scheduling), and [cache locks](https://laravel.com/docs/13.x/cache)
