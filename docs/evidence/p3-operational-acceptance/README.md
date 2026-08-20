# P3 operational acceptance evidence

This folder records the executable evidence for RG-4 operational and client-journey closure. It does not replace the coordinator-owned UAT ledger, feature matrix, or client-ready plan.

## Implemented boundary

- Master Calendar: property-local half-open time windows; reservation state, program, arrival/departure, resource kind, housekeeping, unassigned, and hard-conflict filters; saved member preferences; responsive agenda fallback; Guide-scoped crew lane visibility.
- Allocation workbench: the existing guarded allocation, suggestion, reallocation, swap, and append-only reservation-change services remain authoritative for room, Guide, horse, boat, vehicle, and other configured resource categories.
- Guest operations: privacy-safe duplicate hints, audited canonical merge, ordered companion replacement, occupancy/revision validation, and kitchen projection invalidation.
- Manual inquiries: an inquiry source creates an immutable server-priced `BookingQuote` snapshot; proposal versions preserve that quote; conversion delegates to the exact-once quote commit path.
- Checklists and tasks: immutable published checklist versions, reservation add/edit/remove/reorder exceptions, explicit regeneration, preservation of started/failed/done work, pending-task supersession, and revision-guarded start/complete/fail/reopen/escalate/cancel actions with append-only events.
- Reservation change lifecycle: amendments rebase untouched generated task due dates; cancellation/no-show cancels open reservation tasks and preserves their timelines.
- KPI reconciliation: explicit provisional definitions, property-local ranges, currency separation, null zero-denominator behavior, and a machine-readable client-approval boundary.

Provider delivery and the direct-booking journey were intentionally not rewritten. The immutable commercial pricing, payment-request, Mercado Pago, and front-desk tender flows remain authoritative.

## Automated receipts

The focused suite is `apps/api/tests/Feature/OperationalAcceptanceClosureTest.php`. It covers task lifecycle and stale revisions, checklist regeneration, ordered companions and kitchen facts, Guide allow/deny boundaries, the server-priced inquiry/proposal conversion, and a dense calendar fixture.

Latest isolated SQLite receipts:

```text
Full suite: 365 passed, 24 expected PostgreSQL-only skips, 2,890 assertions
OperationalAcceptanceClosureTest: 7 passed
FrontDeskTenderTest: 7 passed
Focused total: 14 passed, 166 assertions
OPERATIONAL_CALENDAR_BENCHMARK resources=40 reservations=120 days=90 queries_max=11 p50_ms=86.83 p95_ms=88.40
```

Latest isolated PostgreSQL race receipt:

```text
PostgresOperationalAcceptanceConcurrencyTest
PostgresCommercialConcurrencyTest
PostgresFinancialConcurrencyTest
Total: 22 passed, 131 assertions
```

The PostgreSQL suite includes exact boat-resource overlap and unassigned vehicle-category capacity races. The benchmark is a deterministic acceptance fixture, not a production capacity claim.

## Browser receipts

The state-changing Manager journey was exercised against the isolated PostgreSQL stack on desktop and the responsive `390 × 844` override. It verified persisted calendar filters, the compact mobile agenda, an email inquiry, immutable server pricing, proposal v1 send, v2 revision/send, exact-once conversion, reservation confirmation, exact-room allocation, repeat companion mutation, durable dietary/allergy mutation, immediate privacy-safe kitchen projection refresh, checklist v1 publication/materialization, and task start/fail/escalate state changes. The conversion action disappeared after the first conversion and the generated reservation retained the exact `$7,140.00` proposal total.

The separate Guide journey created a Mateo-owned availability block while exposing only Mateo's Guide resource in the picker. Direct Guest and Finance routes returned `403`. The Guide calendar contained only the Guide's assignments and own block; guest-facing operational need was minimized and no finance or payment data was exposed. Browser console review returned no errors, and the temporary viewport override was reset.

## Primary implementation references

- [Laravel database transactions](https://laravel.com/docs/12.x/database#database-transactions)
- [Laravel events and queued listeners](https://laravel.com/docs/12.x/events)
- [Filament resources](https://filamentphp.com/docs/4.x/resources/overview)
- [Filament actions](https://filamentphp.com/docs/4.x/actions/overview)
- [WCAG 2.2](https://www.w3.org/TR/WCAG22/)
- [Playwright projects](https://playwright.dev/docs/test-projects)
