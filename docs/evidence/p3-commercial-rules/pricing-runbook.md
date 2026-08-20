# Commercial pricing operations runbook

## Deterministic calculation contract

Inn evaluates every quote in this order:

1. eligibility and stay/arrival/departure/advance/blackout/capacity restrictions;
2. base, occupancy, guest-category, group, length-of-stay and program components;
3. included and selected optional services;
4. eligible promotions in descending priority, with exclusivity and one winner per stacking group;
5. configured fees and versioned tax inputs;
6. snapshotted deposit and cancellation policies.

All money is integer minor units with an explicit ISO-style three-character currency. Quantities use thousandths. The quote line freezes its basis, quantity, source/version IDs, property-local business date/time zone, pre/post totals, rounding mode and plain-language explanation. The quote checksum covers those lines, the complete normalized input, version snapshots, total, policies and expiry-independent calculation facts.

## Publish and change workflow

- Create a draft rate plan or promotion version in **Setup**.
- Add complete rules/services or promotion applicability and limits.
- Preview representative arrival dates, occupancy boundaries and service selections.
- Publish with an accountable manager/administrator. Published versions cannot be edited; copy a new version and retire the prior version.
- Historical quotes and reservations are projections of immutable snapshots. Do not run the live pricing engine to reconstruct historical revenue.
- An amendment creates a fresh quote and explicit folio/change delta. The previous quote, folio facts and voucher redemption ledger remain intact.

## Voucher handling

- Voucher canonicalization is NFC Unicode normalization, Unicode-whitespace trim, Unicode uppercase, 6–64 characters, and Unicode letter/number plus internal hyphen only.
- Inn stores an HMAC-SHA-256 tenant-scoped hash and public label, never the raw code.
- Public/direct-booking validation must use the `commercial-voucher` limiter and the generic `The promotion could not be applied.` error for unknown, invalid, wrong-tenant/property, expired, exhausted or over-budget codes.
- Commit locks inventory/property and the voucher in one database transaction. It creates a `reserved` redemption with the hold; confirmation appends `confirmed`; eligible expiry/cancellation appends `reinstated`; ineligible cancellation appends `retained`. History is never erased.

## Troubleshooting and reconciliation

- `No sellable rate...`: inspect local business dates/time zone, advance window, stay limits, arrival/departure days, blackout/stop-sell, occupancy, program and buyout applicability.
- `promotion could not be applied`: do not reveal the reason publicly. An authorized manager may inspect promotion state/version/validity, currency/scope and redemption/budget counters.
- `quote snapshot failed its integrity check`: do not override it. Create a new quote and investigate unexpected database mutation/audit history.
- Reconcile a reservation through its booking quote checksum, immutable quote lines, voucher redemption/events, reservation change delta and booked folio facts.
- A rollback must leave both inventory and voucher use absent. PostgreSQL concurrency tests are the release authority for final-unit/final-redemption races.

## Fiscal boundary

Tax-inclusive/exclusive mode, taxable discount allocation, rounding scope/mode and jurisdiction metadata are only versioned pricing inputs. They do not make an operational document a fiscal invoice. See [fiscal-decision-input-template.md](fiscal-decision-input-template.md); Agent 04B remains blocked until it is fully approved.
