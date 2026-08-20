# P3 commercial rules and fiscal-readiness evidence

Branch: `codex/p3-commercial-rules`
Base commit: `5092d682caee365f069ad0734c60cebd96f512bc`
Migration namespace: `2026_08_20_04xxxx`
Implementation date: 2026-08-20

## Scope delivered

- Versioned rate plans/rules with draft/publish/retire/copy workflow and immutable published versions.
- Property-local advance/stay/arrival/departure/weekday/blackout/occupancy/program/buyout restrictions.
- Adult/child/infant, single-supplement, group-tier, length-of-stay, program, included-service and optional-service price components.
- Versioned promotions with applicability, priority, stacking/exclusivity, owner, validity, usage/per-guest/budget limits and cancellation reinstatement policy.
- NFC/trim/uppercase/allowed-character voucher canonicalization, tenant-keyed HMAC hashes, generic errors and no raw-code persistence.
- Atomic hold-time voucher reservation, confirmation, eligible expiry/cancellation reinstatement and append-only lifecycle events.
- Immutable quote calculation facts/checksum, server-authoritative totals, staff explanation endpoint and amendment deltas without historical rewrite.
- Versioned tax-input/rounding fields and immutable non-fiscal fiscal-source snapshots behind an interface for a future named connector.

## Explicitly not delivered — Agent 04B gate

No regulated fiscal invoice, tax-authority call, legal numbering, point of sale, certificate, tax registration/rate decision, jurisdiction-specific cancellation/credit-note behavior or legal-compliance claim is implemented. The required decision record is [fiscal-decision-input-template.md](fiscal-decision-input-template.md). Until approved, existing P3-03 confirmation/folio/receipt/refund/credit outputs remain operational and non-fiscal.

## Primary implementation references

Retrieved 2026-08-20:

- [Laravel 13 database transactions](https://laravel.com/docs/13.x/database) — transactional rollback and deadlock retry boundary.
- [Laravel 13 query builder locking](https://laravel.com/docs/13.x/queries#pessimistic-locking) — `lockForUpdate` inside transactions.
- [Laravel 13 validation](https://laravel.com/docs/13.x/validation) — request/domain input validation.
- [Laravel 13 rate limiting](https://laravel.com/docs/13.x/rate-limiting) — generic public validation throttling.
- [Filament 5 resource testing](https://filamentphp.com/docs/5.x/testing/testing-resources), [action testing](https://filamentphp.com/docs/5.x/testing/testing-actions), and [security](https://filamentphp.com/docs/5.x/advanced/security) — tenant scoping, custom-action authorization and Livewire action coverage.

No jurisdictional tax-authority reference is cited because no jurisdiction or fiscal issuance decision was approved. Agent 04B requires current official authority documentation on its implementation day.

## Verification ledger

| Gate | Result | Evidence |
| --- | --- | --- |
| PHP syntax | PASS | All application and migration PHP files parsed without syntax errors. |
| Full SQLite API suite | PASS | 319 passed, 2,417 assertions, 17 skipped; 49.12 s. |
| Full PostgreSQL API suite | PASS | Fresh `inn_test`: 336 tests, 2,517 assertions, 1 skipped; 71.21 s. |
| Focused booking/amendment/commercial regression | PASS | 32 tests, 183 assertions after browser-found UI fix. |
| Commercial rules functional matrix | PASS | 7 tests; restrictions, tax allocation/rounding, components, Unicode/case, session limits, lifecycle/amendment and fiscal snapshot. |
| OpenAPI route parity | PASS | 94 paths, 129 operations, 104 resolved references. |
| PostgreSQL migrate/race | PASS | Commercial focused/race suite: 10 tests, 75 assertions; final inventory and voucher winner/rollback invariants. |
| Filament/browser UAT | PASS | Manager published rate plan/promotion/voucher; staff quote showed USD 6,000.00 rooms − USD 25.00 + USD 1,135.25 tax = USD 7,110.25; hold `01a01ff0-52fa-7020-a7c4-36ab6332cefe` was confirmed, amended one night to USD 10,680.25 with USD 3,570.00 delta, then cancelled. PostgreSQL showed distinct committed original/replacement checksums, unchanged original facts, and `reserved → confirmed → reinstated` voucher events. Raw-code storage search returned zero rows. |
| Benchmark | PASS | Maximum 15 queries; p95 seasonal 7.29 ms, group 7.29 ms, buyout 7.07 ms. |
| API/web builds and static analysis | PASS | API Vite build; web Next production build; Pint 734 files; PHPStan no errors; ESLint and TypeScript pass. |
| Browser regression | PASS | Public web 4/4; isolated client closed-loop 7 passed, 1 provider-gated skip. |
| Dependency/security scan | PASS | Composer and production npm audits: zero advisories; staged gitleaks and explicit high-risk secret-pattern scans: no findings. |

## Hotspots and integration notes

- `contracts/openapi.yaml` adds the authenticated historical quote-explanation operation and richer quote contract.
- `apps/api/routes/api.php` adds `GET /api/v1/booking-quotes/{bookingQuote}`.
- `AppServiceProvider.php` registers the fiscal snapshot boundary, commercial model audits and a voucher limiter.
- `Reservation.php` overlap is additive only: the `infants` cast and `voucherRedemptions()` relation.
- `ReservationService` and `CloseReservationWithPolicy` add transaction-local voucher confirm/release hooks; payment/accounting behavior is unchanged.
- Browser UAT found that Filament's password/reveal wrapper cleared the staff voucher value before the live quote refresh. The voucher field remains ephemeral but is now a plain text input; only the tenant-keyed HMAC is persisted.
