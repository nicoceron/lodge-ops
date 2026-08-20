# Agent 04 — Commercial rules, promotions, pricing, and fiscal readiness

## Copy/paste assignment

> Complete the commercial rule engine needed by staff and direct booking. Start from synchronized `main` after P3-06A. Read this file, the coordinator README, Rincón Grande requirements, the launch gap map, all quote/rate/tax/policy/deposit services, and current tests before editing. Add advance-booking and stay restrictions, guest/group/program pricing, included/optional services, promotion/voucher lifecycle, deterministic stacking/rounding, immutable quote snapshots, explainability and state-changing staff UAT. Do not create jurisdiction-specific fiscal invoices or tax claims without approved legal inputs; make the boundary ready and record the decision gate honestly.

## Branch and ownership

- Branch: `codex/p3-commercial-rules` after Agent 00.
- Own rate/restriction/promotion/voucher/service-pricing domain, quote calculations/snapshots, management UI/API/OpenAPI/tests, pricing runbook and fiscal decision contract.
- Preserve `BookingQuoteService`, `CommitBookingQuote`, authoritative server pricing and existing reservation amendment semantics.
- Do not own payment capture, provider settlement, report engine reimplementation, direct-booking UI, or legal-provider integration.
- Read: `RatePlan`, `RateRule`, booking quote models/services, availability/commit/reservation/payment schedule services, catalog/service occurrence models, cancellation/tax/property settings, Filament rate resources, quote/reservation tests.

## Required commercial model

- Versioned restrictions: sale/open-close, minimum/maximum advance booking, minimum/maximum stay, closed-to-arrival/departure, allowed arrival days, blackout, capacity/occupancy, resource/program and buyout applicability.
- Versioned price components: base/night/category/program, adults/children/infants, single supplement, group tiers, length-of-stay, weekday/season, included service, optional add-on, fee/tax/discount, deposit and cancellation policy.
- Promotion/voucher: opaque code hash where appropriate, public label, validity/property/currency/applicability, usage and budget limits, per-guest/session restrictions, stacking/exclusivity/priority, approval owner, state and immutable redemption ledger.
- Freeze voucher canonicalization before hashing: Unicode normalization form, trim policy, case behavior and allowed characters/length. The same canonicalizer is used by management, public validation, uniqueness and audit without retaining unnecessary raw codes.
- Store integer minor units, currency, quantity/basis, rule/version IDs, local business dates/time zone, calculation order, pre/post totals, rounding mode and explanation on each quote line.
- Define one deterministic order: eligibility/restrictions → base/occupancy/program → included/optional services → promotions → fees/taxes → deposit. Document any jurisdiction-approved exception.
- Treat tax-inclusive versus tax-exclusive pricing, taxable discount allocation and per-line versus total rounding as versioned fiscal inputs. Do not infer them from currency or locale.
- Quote checksum and expiry cover every rule version/input. Commit must reject stale/mutated quotes; it never trusts client totals, promotion discount or deposit.
- Amendments create a new priced snapshot and explicit delta; never rewrite the booked snapshot or voucher usage.

## Voucher concurrency and lifecycle

- Reserve redemption atomically with the reservation hold/booking order; confirm on booking; release only on eligible expiry/cancel according to policy.
- Unique command identity prevents duplicate use. PostgreSQL row locks/constraints prevent two last-redemption winners.
- Cancellation/refund does not erase redemption history; add an append-only reversal/reinstatement event with actor/policy reason.
- Never disclose whether a code belongs to another tenant/property. Rate-limit public validation and return generic errors.

## Staff UX and explainability

- Filament CRUD with versioned publish/retire workflow, preview calendar, conflict validation, copy/new-version action and role/property policies.
- Quote UI shows included vs optional, per-night/per-person/service quantities, discount source, fee/tax basis, deposit and cancellation policy in plain language.
- Add a Finance/Manager “explain this quote” projection that resolves every line to the immutable input/rule/version without recalculating historical totals.
- Export/report projections consume booked facts; they must not call the live pricing engine for historical revenue.

## Fiscal boundary

- Produce a decision record/template for legal entity, tax domicile, supported jurisdiction, invoice class/type, tax IDs/rates, numbering authorization, point of sale, issuance time, cancellation/credit-note rules, currency/exchange rate and provider/API.
- Keep current P3-03 outputs labeled confirmation/folio/receipt/credit document, not regulated fiscal invoice, until that record is approved.
- Provide interfaces/events and immutable fiscal source snapshot so a later named fiscal connector can issue and reconcile. Do not invent numbering or call a PDF legally compliant.
- If approved inputs arrive during this assignment, create a separate reviewed branch/plan for that jurisdiction rather than burying regulated behavior inside generic pricing.
- Write all evidence and unresolved decisions to `docs/evidence/p3-commercial-rules/README.md`; never use the protected master documents as the slice scratchpad.

## Tests and acceptance

- Matrix: same-day/long stay/buyout, cutoff before/at/after, min/max advance/stay, CTA/CTD, blackout, capacity, property time zone/DST.
- Adults/children/group boundaries, included/optional service quantities, zero/negative/overflow rejection, mixed currencies, half-cent/rounding and exchange-rate exactness.
- Promotion: valid/expired/future, exclusive/stacked priority, limits/budget, case/Unicode, brute force, same code other tenant, amendment/cancel/expiry/replay.
- PostgreSQL races for final inventory plus final voucher use; commit rollback restores both or neither.
- Role/property/IDOR; public-safe errors; audit/history; Filament actions.
- Browser UAT: manager publishes rules/code → staff searches → quote explains price/deposit → commits hold → voucher becomes used → amendment produces delta → cancel follows reinstatement policy → historical quote remains unchanged.
- Benchmark representative seasonal/group/buyout searches and record query count/p95; eliminate N+1.
- Run universal gates and full booking/payment regression.

## Primary references

- [Rincón Grande requirements](../rincon-grande-requirements.md)
- [Laravel database transactions](https://laravel.com/docs/13.x/database), [validation](https://laravel.com/docs/13.x/validation), [cache locks](https://laravel.com/docs/13.x/cache), and [rate limiting](https://laravel.com/docs/13.x/rate-limiting)
- [Filament 5 resource testing](https://filamentphp.com/docs/5.x/testing/testing-resources) and [action testing](https://filamentphp.com/docs/5.x/testing/testing-actions)
