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
| Full SQLite API suite | PASS | 327 passed, 2,460 assertions, 17 skipped; 49.07 s. |
| Full PostgreSQL API suite | PASS | Fresh `inn_test`: 343 passed, 2,560 assertions, 1 skipped; 74.04 s. |
| Reviewer regression matrix | PASS | 8 focused SQLite tests, 42 assertions: priority/no-fallback, non-UTC/DST local dates and departure CTD, atomic publication, per-promotion usage, currency-filtered tax, fiscal extras/payments/refunds/reversals, all-role policy and migration backfill/rollback guard. |
| Commercial rules functional matrix | PASS | Full suites cover restrictions, tax allocation/rounding, components, Unicode/case, guest/session/budget limits, voucher lifecycle, amendment replacement and fiscal reconciliation. |
| OpenAPI route parity | PASS | 94 paths, 129 operations, 104 resolved references. |
| PostgreSQL migrate/race | PASS | Reviewer-focused migration/race suite: 9 passed, 48 assertions; full PostgreSQL suite also exercises inventory and promotion/voucher winner/rollback invariants. |
| Filament/browser UAT | PASS | Refreshed Compose/PostgreSQL: staff created and published a program/category/group-tier rate plan; created and published an automatic promotion with rate-plan/category/program applicability and guest/session/budget limits; created and published a code promotion; issued an opaque voucher, suspended it, then retired it. A quote-backed hold opened `Historical quote explanation` with immutable USD subtotal 346,500, discount 38,500, tax 65,835 and total 412,335 minor units. |
| Benchmark | PASS | Maximum 14 queries; p95 seasonal 3.64 ms, group 3.05 ms, buyout 3.15 ms. |
| API/web builds and static analysis | PASS | API Vite build; web Next production build; Pint 740 files; PHPStan no errors; ESLint and TypeScript pass. |
| Browser regression | PASS | Public web 4/4; isolated client closed-loop 7 passed, 1 provider-gated skip. |
| Dependency/security scan | PASS | Composer and production npm audits: zero advisories; staged gitleaks and explicit high-risk secret-pattern scans: no findings. |

## Hotspots and integration notes

- `contracts/openapi.yaml` adds the authenticated historical quote-explanation operation and richer quote contract.
- `apps/api/routes/api.php` adds `GET /api/v1/booking-quotes/{bookingQuote}`.
- `AppServiceProvider.php` registers the fiscal snapshot boundary, commercial model audits and a voucher limiter.
- `Reservation.php` overlap is additive only: the `infants` cast and `voucherRedemptions()` relation.
- `ReservationService` and `CloseReservationWithPolicy` add transaction-local voucher confirm/release hooks; payment/accounting behavior is unchanged.
- Browser UAT confirmed the voucher secret remains an ephemeral password/reveal field and never appears in the voucher list; only the tenant-keyed HMAC is persisted.
