# P3-06C Point and QR Orders evidence — 2026-08-20

Release classification: **implementation/test mode (level 1) is complete; real QR sandbox (level 2) and physical Point (level 3) are not claimed.**

No access token, HMAC secret, endpoint key, signature, QR payload, guest PII, or full live provider identifier is stored in this directory.

## Implemented closure

- A separate Mercado Pago Orders gateway owns Point/QR create, lookup, cancel, refund, terminal listing, normalization, and signed singular-`order` verification. Checkout Pro retains its Payments gateway and `payment` event processor.
- Connection identity includes tenant, property scope, provider, product, provider application, external seller account, and environment. Point/QR capabilities and exact charge currency must be enabled; terminal/POS mappings cannot be silently reassigned across properties/connections.
- Staff requests have no public token. PostgreSQL partial unique indexes and financial lock order enforce one active order per request, terminal, or reusable QR POS.
- Create/cancel/refund use stable operation-specific idempotency identities and canonical checksums. A create timeout after remote success resumes the same attempt and safely reissues the same body/key; busy/already-queued and other ambiguous outcomes retain one recoverable operation.
- Money applies only after application/account/environment/type/reference/device-or-POS/amount/currency/order validation and exactly one nonempty/unique processed-accredited transaction whose amount and paid amount equal the request. Duplicate/reordered/late events and concurrent approval retain one Payment, folio effect, receipt request, and settlement revision.
- Point `at_terminal` cancel becomes terminal action required. `action_required` remains sticky. Late approval after cancellation/expiry/failure becomes Finance mismatch and does not apply money.
- QR buyer rejection is not invented as an order failure. Active QR is encrypted at rest, hidden exactly at local order expiry, and purged by the one-server expiry command; the staff response renders a real QR, not the raw payload.
- Orders refunds use `/v1/orders/{id}/refund`; Point 90/91-day and QR 360/361-day boundaries are tested, and provider-action-required refund does not complete local accounting.
- Filament/API/OpenAPI expose terminal/POS registry, Point/QR initiation/status/cancel/reconcile/refund, active queues, and role/property/IDOR boundaries.

## Recorded gates

- After merging current `main`, Pint and PHPStan passed with zero errors.
- The combined direct-booking, payment, tender, and integration-kernel compatibility selection passed `111` tests (`102` passed, `9` expected PostgreSQL-only skips) with `1,144` assertions.
- OpenAPI plus the independently versioned direct-booking contract passed at `145` paths, `182` operations, and `123` resolved references; direct booking retained `12` paths, `15` states, `13` errors, and `25` fixtures.
- The repair SQLite suite passed `482` tests (`446` passed, `36` expected PostgreSQL-only skips) with `3,714` assertions. The repair PostgreSQL suite passed all `482` tests with `3,888` assertions and one explicit non-PostgreSQL/environment skip; the post-change focused PostgreSQL regression passed `38` tests with `334` assertions.
- The focused PostgreSQL terminal/request/application race suite passed `3/3` tests with `17` assertions. The formerly failing integration-kernel, Point/QR lifecycle, and central sensitive-data paths passed `35/35` with `431` assertions before the final full run.
- Pint, PHPStan, ESLint, and TypeScript passed; the Laravel Vite and Next.js production builds completed. Composer and both npm audits reported no advisories/vulnerabilities.
- The fresh isolated `inn_agent02_repair` Compose stack ran with API `8307`, web `3307`, PostgreSQL `5647`, and Redis `6587`; fresh migration, API/web health checks, public site, normal worker, scheduler, PostgreSQL, Redis, and Mailpit passed.
- Repair Chromium passed `3/3`: Point traversed signed HTTP delivery and the normal `provider-events` worker into one approved payment, receipt, and settlement; QR rendered a real SVG at `390`, `768`, and `1440` pixel widths and disappeared after authoritative approval; a second QR was visible before expiry then expired/purged and absent at all three widths. The public-browser suite previously passed `4/4`, and the tender browser regression passed its focused journey `1/1`.
- The staged-diff gitleaks scan, explicit high-risk credential-pattern scan, whitespace check, and protected-coordinator-document check are recorded after the final evidence edit and before publication; no live provider value is intentionally present in the changeset.

## Deterministic worker/browser journey

The local-only `payments:in-person-compose-uat` command creates an ARS fixture reservation, canonical Orders connection, Point terminal and QR POS, and an active provider attempt. The dedicated Playwright spec submits a correctly signed HTTP `order` notification to the real webhook route and waits for the normal Compose worker to perform authoritative lookup. It checks exact-once Payment/folio/receipt/settlement state and verifies dynamic QR presence/removal at mobile, tablet, and desktop widths.

All resulting order/transaction IDs and QR payloads are synthetic deterministic fixtures. This proves software behavior, not a Mercado Pago transaction.

## Provider evidence boundary

The available authorized credential was checked read-only and identifies a Colombia/MCO test account. The requested P3-06C certification path is Argentina/MLA + ARS, and current Mercado Pago availability material does not establish Point/QR Orders for the available account. No provider mutation was attempted with the incompatible credential.

Therefore:

1. **Implementation/test mode:** implemented; final local/CI gates recorded in this file.
2. **Real QR sandbox:** blocked pending an authorized supported seller application/account, ARS connection, provisioned store/POS, test buyer, and signed-event/refund permission. No real scan/payment/refund is claimed.
3. **Physical Point:** blocked pending an authorized production merchant and real Point device in PDV mode. No physical card, device receipt, terminal-action refund, or provider settlement is claimed.

Mercado Pago says test accounts cannot make real physical Point payments and its normal virtual device is not a valid integration-quality measurement. The local console harness can call the provider test-only `/events` route only with explicit authorization and a compatible account; it is absent from HTTP routes and was not run against the MCO credential.

## Live documentation discrepancy

On 2026-08-20, current QR material disagreed on refund age: the processing narrative states 180 days, while Orders migration/API error material states 360 days. Inn enforces the assigned 360-day ceiling and treats any provider denial inside it as authoritative, persisting the reason. Tests at 360 days are software-boundary evidence only, not proof that every merchant/API version accepts the refund.

Current Point cancellation pages also disagree: payment-processing/state guidance describes `at_terminal` cancellation at the physical terminal, while the migration page documents an opt-in `x-allow-cancelable-status: at_terminal` API header. Inn conservatively retains terminal-side cancellation and does not send the opt-in header without authorized real-device certification.

See the [contract snapshot](provider-contract-snapshot.md) and [operations runbook](../../p3-06c-mercado-pago-point-qr-runbook.md) for source links and activation procedure.
