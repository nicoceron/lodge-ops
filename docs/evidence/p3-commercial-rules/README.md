# P3 commercial rules and fiscal-readiness evidence

Branch: `codex/p3-commercial-rules`
Base commit: `5092d682caee365f069ad0734c60cebd96f512bc`
Migration namespace: `2026_08_20_04xxxx`
Implementation date: 2026-08-20

## Scope delivered

- Versioned rate plans/rules with draft/publish/retire/copy workflow, one atomic current publication per identity and immutable published versions.
- Property-local advance/stay/arrival/departure/weekday/blackout/occupancy/program/buyout restrictions.
- Adult/child/infant, single-supplement, group-tier, length-of-stay, program, included-service and optional-service price components.
- Versioned promotions with applicability, priority, stacking/exclusivity, owner, validity and atomic usage/per-guest/per-session/budget limits.
- NFC/trim/uppercase/allowed-character voucher canonicalization, tenant-keyed HMAC hashes, generic errors and no raw-code persistence.
- Per-promotion immutable usage facts, voucher-specific discount attribution, atomic hold-time reservation, amendment replacement/delta facts and append-only lifecycle events.
- Immutable quote calculation facts/checksum, server-authoritative totals, staff explanation endpoint and amendment deltas without historical rewrite.
- Versioned currency-aware tax-input/rounding fields and immutable, folio-revision-keyed non-fiscal source snapshots behind an interface for a future named connector.

## Explicitly not delivered — Agent 04B gate

No regulated fiscal invoice, tax-authority call, legal numbering, point of sale, certificate, tax registration/rate decision, jurisdiction-specific cancellation/credit-note behavior or legal-compliance claim is implemented. The required decision record is [fiscal-decision-input-template.md](fiscal-decision-input-template.md). The 04B decision is pending and issuance is blocked; it is not classified as deferred unless the client supplies a separate written deferment. Until approved, existing P3-03 confirmation/folio/receipt/refund/credit outputs remain operational and non-fiscal.

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
| Full SQLite API suite | PASS | 331 passed, 2,605 assertions, 17 expected PostgreSQL-only skips; 71.16 s. |
| Full PostgreSQL API suite | PASS | Fresh `inn_test` through `phpunit.pgsql.xml`: 348 tests, 2,705 assertions, 1 expected host-path skip; 106.17 s. |
| Reviewer regression matrix | PASS | Latest second-review plus migration focus passed identically on SQLite and PostgreSQL: 7 tests / 194 assertions per engine. Coverage includes governing-rule and authorization closures, stable staff session identity, pre-DDL rollback, immutable voucher-redemption facts, restrictive historical guest foreign keys, legacy-data upgrade/down/re-up, atomic deletion rejection, PII-cleared guest merge tombstones, refund lifecycle/idempotency and complete historical projection. |
| Commercial rules functional matrix | PASS | Full suites cover restrictions, tax allocation/rounding, components, Unicode/case, guest/session/budget limits, voucher lifecycle, amendment replacement and fiscal reconciliation. |
| OpenAPI route parity | PASS | 94 paths, 129 operations, 104 resolved references. |
| PostgreSQL migrate/race | PASS | Fresh production-engine suite exercised PostgreSQL constraints plus inventory, payment, refund, idempotency and promotion/voucher winner/rollback races; all 348 tests passed except one expected host-path skip. |
| Filament/browser UAT | PASS | Fresh Compose/PostgreSQL staff journey created an automatic per-session promotion and an opaque voucher, priced both into a live quote, committed a hold, amended departure by one night, confirmed, cancelled and observed policy-driven reinstatement. The history workflow exposes both immutable quote versions with nightly bases/running totals, rate-rule and rate-plan version 1, tax-input version 1, deposit/cancellation JSON, and every automatic-promotion and voucher reserved/superseded/confirmed/released event. Database verification found cancelled status, two immutable quotes, the same 64-character session identity on old/replacement usages, four released per-promotion usages and two released voucher redemptions. |
| Benchmark | PASS | Maximum 14 queries; SQLite p95 seasonal 4.06 ms, group 3.19 ms, buyout 3.24 ms (PostgreSQL p95 9.32/7.34/7.31 ms). |
| API/web builds and static analysis | PASS | API Vite build with clean Linux dependencies; web Next production build; Pint 741 files; PHPStan no errors; ESLint and TypeScript pass. |
| Browser regression | PASS | Public web 4/4; isolated client closed-loop 7 passed, 1 provider-gated skip. |
| Dependency/security scan | PASS | Composer and production npm audits: zero advisories; staged gitleaks and explicit high-risk secret-pattern scans: no findings. |

## Hotspots and integration notes

- `contracts/openapi.yaml` adds the authenticated historical quote-explanation operation and richer quote contract.
- `apps/api/routes/api.php` adds `GET /api/v1/booking-quotes/{bookingQuote}`.
- `AppServiceProvider.php` registers the fiscal snapshot boundary, commercial model audits and a voucher limiter.
- `Reservation.php` overlap is additive only: the `infants` cast and `voucherRedemptions()` relation.
- `ReservationService` and `CloseReservationWithPolicy` add transaction-local voucher confirm/release hooks; payment/accounting behavior is unchanged.
- Browser UAT confirmed the voucher secret remains an ephemeral password/reveal field and never appears in the voucher list; only the tenant-keyed HMAC is persisted.
