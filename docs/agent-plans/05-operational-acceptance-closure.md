# Agent 05 — Operational and client-journey acceptance closure

## Copy/paste assignment

> Close the durable non-provider client gaps. Non-pricing work may begin after P3-06A, but rebase and merge only after Agent 04 so companion/reservation changes use final commercial rules. Read this file, the coordinator README, every RG-4 requirement, current UAT ledger, master calendar/allocation/reservation/proposal/kitchen/task/report code and tests. Implement the missing staff/Guide resource workbench, inquiry/proposal/repeat-guest/companion mutations, calendar scale/mobile/conflict behavior, editable checklist exceptions, kitchen updates, task exception lifecycles and KPI reconciliation with real browser journeys. Preserve existing reservation/payment invariants. Do not rewrite gateways, production communications, or direct booking.

## Branch and ownership

- Branch: `codex/p3-operational-acceptance` may start after Agent 00; rebase and final acceptance/merge require Agent 04.
- Own Master Calendar operational UX/performance, guide/horse/boat/vehicle allocation workbench, repeat guest/companion editing, kitchen projection mutation proof, task exception lifecycle, KPI definition/reconciliation tests, related API/Filament/Playwright/docs.
- Do not own rates/promotions (Agent 04), provider delivery (Agent 03), public booking (Agents 06–09), or production infrastructure.
- Read fully: RG-4.1–4.11, `client-uat-ledger.md`, `MasterCalendar`, calendar projections/API, allocations/resource suggestions/change commands, reservation guest/merge services, KitchenDashboard, tasks/templates/provisioning, finance/dashboard projections and their tests.

## Calendar acceptance

- One calendar supports room/category, guide and shared-resource lanes with property-local date boundaries and conflict truth from domain services.
- Add filters for property, resource type/status, program/group, reservation state, arrival/departure, housekeeping and conflict/unassigned state; persist safe user preferences.
- Long stays, same-day departure/arrival turnover, buyout/exclusive use, activities crossing days, maintenance blocks, partial overlaps and DST must render correctly.
- Drag/drop or action-based changes must call guarded commands with confirmation, version/idempotency and actionable conflict errors; never mutate allocation rows directly.
- Responsive phone/tablet view must remain operable, not merely horizontally clipped. Keyboard-accessible alternatives are required.
- Seed representative 90-day/high-volume data, assert bounded query count/no N+1, record p50/p95 and a stated budget; empty and dense calendars both pass.

## Shared-resource allocation workbench

- Staff can see required/unassigned/conflicted guide/horse/boat/vehicle needs, ranked suggestions and availability reason.
- Implement assign, move, swap and release using reservation-first locks, resource blocks, capacities/skills, property scope and immutable reservation change entries.
- Late changes update affected tasks/kitchen/communications through after-commit domain events; existing dispatched history remains immutable.
- Concurrency proves two agents cannot allocate the same exclusive resource or exceed shared capacity.
- Guide self-service is a separate least-privilege surface: a Guide updates only their own availability, sees only their own assignments and minimized guest/operational details, and cannot inspect another Guide, finance, full folio/payment, unrelated guest or other-property data.

## Guest, kitchen, task, and KPI closure

- Add repeat-guest selection with duplicate warnings, stay history, privacy-safe search, and explicit create-new/merge path.
- Add companion add/edit/remove/reorder with lead-guest protection, occupancy/price revalidation, audit actor and post-confirmation amendment semantics.
- Prove the manual-sales path: capture inquiry source (`email`, `whatsapp`, phone, walk-in or approved source) without importing private message bodies → create server-priced proposal → version/revise/send intent → convert exactly once to hold/reservation → select repeat guest/preferences/history → confirm. Proposal conversion never trusts client totals or duplicates voucher/inventory usage.
- Browser journey must mutate dietary/allergy/meal/guest details and prove the kitchen projection changes, role scope is enforced, and stale/canceled guests disappear according to policy.
- Checklist templates are editable only through publish/version/retire. Manager can add/reorder/retire role-specific standard items; a reservation may add/edit/remove/reorder explicit exceptions without mutating the template. Amendment/regeneration supersedes only pending generated tasks exactly once and preserves started/completed/failed history.
- Task lifecycle: provision from program/version, assign, start, complete, fail with reason, reopen/escalate, overdue projection/notification intent, cancellation/amendment supersession and preserved audit.
- Emit committed domain/outbox facts for Agent 03 communication occurrences; do not implement a second scheduler or delivery pipeline here.
- Reconcile occupancy, ADR/revenue/deposit/outstanding/arrival/departure/task/Kitchen KPI formulas to authoritative rows. Add a definition table with numerator, denominator, time zone, currency, exclusions and reconciliation query. If client approval is absent, mark definitions provisional; that can pass implementation/demo but blocks final **Client-ready** unless formally deferred.

## Required tests and UAT

- PostgreSQL overlap/capacity races, stale version, same command replay, swap rollback and allocation-versus-cancel/amend.
- Calendar boundary/filter/role/property tests plus performance fixture/query assertions.
- Guest duplicate/merge/companion occupancy/pricing/concurrency/privacy tests.
- Kitchen mutation and task overdue/failure/reopen/cancel/amend projections; empty/high-volume cases.
- Checklist template versioning, reservation-specific add/edit/remove/reorder, exact-once generation and amendment/cancel supersession.
- Inquiry-source/proposal version/send/conversion replay and repeat-guest path; source metadata is safe and no raw email/WhatsApp content leaks.
- KPI ledger reconciliation, multi-currency disclosure, property-local half-open dates and zero-denominator behavior.
- Filament action visibility/authorization and API/OpenAPI tests.
- Browser UAT at desktop and 390×844: inquiry/source → versioned proposal → server-priced repeat-guest reservation → confirm → assign room/resources → calendar persistence → edit companion/diet → kitchen changes → publish/customize checklist → task failure/escalation/completion → amend/move/release → dashboard reconciles.
- Separate Guide browser: update own availability → see own assignment/minimized guest need → deny other Guide, finance, payment, unrelated guest and other-property access.
- Run universal gates. Store trace/screenshots only when redacted; clean up through normal lifecycle, not database deletion.

## Primary references

- [Rincón Grande requirements](../rincon-grande-requirements.md)
- [Filament 5 resource testing](https://filamentphp.com/docs/5.x/testing/testing-resources) and [action testing](https://filamentphp.com/docs/5.x/testing/testing-actions)
- [Laravel database](https://laravel.com/docs/13.x/database), [events](https://laravel.com/docs/13.x/events), and [cache locks](https://laravel.com/docs/13.x/cache)
- [WCAG 2.2](https://www.w3.org/TR/WCAG22/) and [Playwright projects](https://playwright.dev/docs/test-projects)
