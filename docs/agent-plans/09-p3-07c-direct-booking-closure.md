# Agent 09 — P3-07C direct-booking adversarial closure

## Copy/paste assignment

> Close direct booking only after production communications and the domain/API and public UX branches are merged. Read this file, the coordinator README, all three direct-booking plans/evidence, P3-06A/P3-04 runbooks and current provider docs. Do not add unrelated features. Find and fix integration/concurrency/accessibility failures, run the real sandbox and communication journey, and leave a reproducible release runbook. No mock, return URL, manually edited row or direct service invocation may substitute for the public browser → provider → signed event → ordinary worker → confirmation path.

## Branch and scope

- Branch: `codex/p3-07c-direct-booking-closure` after Agents 03, 07 and 08.
- Own only cross-slice corrections, adversarial tests, end-to-end fixtures, monitoring hooks, evidence/runbook and honest completion language.
- Any change to pricing/inventory/payment/communications invariants requires focused regression and review from that slice owner.

## Mandatory adversarial matrix

- Two guests compete for the final unit; same/different quote and voucher; only one confirmed allocation.
- Same idempotency key/same body replay and same key/different body rejection at every mutation.
- Quote, voucher reservation, hold, direct session and provider checkout expire before/at/after boundary.
- Return before event, event before return, duplicate/reordered/delayed event, provider lookup timeout, local crash after remote success, and competing payment attempts.
- Approved, pending, rejected, canceled, partial/full refund, chargeback and provider/account/amount/currency/reference mismatch for Checkout Pro; evidence pending/rejected/corrected/approved and hold-expiry behavior for manual bank transfer.
- Late approval after hold release or inventory loss enters paid-needs-review, creates no overbooking, and completes the refund/reconciliation workflow.
- Amendment/price change/cancellation while checkout active; communication or document generation failure after payment; retries complete without another charge.
- Long stay, same-day turnover, group/program/buyout, promotion limit race, optional services, property-local DST and multi-currency rounding.
- Rate-limit isolation, Turnstile outage/failure, token replay/rotation, cross-property/tenant substitution, email enumeration, malicious input and analytics/log PII scan.
- Phone/tablet/desktop, keyboard-only, reduced motion, timeout warning, Spanish/English, browser back/refresh/network loss.

## Real closed-loop acceptance

1. Start from an anonymous browser and a client-like property configuration.
2. Search, obtain authoritative quote, accept versioned policies, create hold/deposit/payment request and open hosted Checkout Pro.
3. Complete each enabled public payment choice: a supported sandbox hosted payment whose return remains pending until signed event/authoritative reconciliation passes through the ordinary worker, and a manual bank-transfer evidence → Finance review path when enabled.
4. Prove exactly one reservation confirmation, allocation, payment, deposit satisfaction, folio line, receipt/document request, task provisioning and communication occurrence.
5. Open the real confirmation email, receipt/document and guest portal on a 390×844 context.
6. Exercise a failed/retry checkout and a late-payment/refund case.
7. Verify staff sees booking/calendar/payment/communication truth and an unauthorized role/property cannot.
8. Re-run delivery/event/refresh and prove no duplicate state.
9. Revoke/expire UAT links and retain only redacted IDs/checksums.

## Performance and release gates

- Define/search/quote/hold/status p95 budgets for a representative 90-day data set; record DB query count and eliminate N+1.
- Prove launch-readiness failure and recovery by disabling one required rate/policy/payment/template/content configuration, observing fail-closed behavior, repairing it through authorized UI and completing the journey.
- Run load/concurrency at the final-unit and voucher-limit hotspots; error rate and invariant violation must be zero.
- Accessibility has no critical/high findings and manual keyboard path passes.
- Provider, inbox and worker evidence is reproducible by runbook; secrets are injected, rotated/revoked and absent from Git/artifacts.
- Run universal gates, PostgreSQL races, public/authenticated Playwright, dependency/security/credential scans and Docker smoke.
- Completion claim states exact provider country/currency and whether evidence is sandbox or production. It must not imply physical card/Point, OTA, fiscal invoice, or production DR.

## Primary references

- [Playwright projects](https://playwright.dev/docs/test-projects) and [trace viewer](https://playwright.dev/docs/trace-viewer)
- [WCAG 2.2](https://www.w3.org/TR/WCAG22/)
- [Laravel queues](https://laravel.com/docs/13.x/queues), [cache locks](https://laravel.com/docs/13.x/cache), and [rate limiting](https://laravel.com/docs/13.x/rate-limiting)
- [Checkout Pro test purchases](https://www.mercadopago.com.ar/developers/es/docs/checkout-pro/integration-test/test-purchases)
