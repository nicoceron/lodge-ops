# Rincón Grande client requirements

Baseline date: 2026-08-18  
Source: client feature brief supplied in the project conversation  
Traceability: [product matrix](feature-matrix.md), [UAT ledger](client-uat-ledger.md), [phase 3 execution plan](client-ready-phase-3-plan.md)

## Scope rule

This file is the durable product-requirement baseline. The source brief labels several capabilities as **ampliación**. Because the current direction is full functionality, those capabilities remain in scope unless the client explicitly defers them in writing. Provider-dependent capabilities are still blocked until a provider, account owner, jurisdiction, and production operating model are selected.

## Client-supplied requirements

### RG-4.1 — Master calendar

- Show accommodation and additional activities such as riding, fishing, and trekking in one date-based view.
- Distinguish closed programs such as Red Stag Hunting and The Patagonian Double from simple stays and day programs using clear colors and labels.
- Show full-lodge buyouts prominently enough to prevent overlapping reservations from one day through multi-week stays.
- Show per-resource occupancy for guides, horses, boats, vehicles, and other constrained resources.

Acceptance requires a real authenticated journey for long stays, same-day checkout/check-in boundaries, buyouts, activities, resource conflicts, filters, roles, phone layout, and representative fixture performance.

### RG-4.2 — Guides and shared-resource allocation

- Maintain guide availability, specialties, and languages.
- Let each program define required capabilities and ratios such as one guide per guest or one guide per two guests.
- Suggest or reject assignments based on availability, capability, language, capacity, and overlap.
- Apply the same bounded allocation mechanism to horses, boats, transfer vehicles, and similar resources.

Acceptance requires concurrent conflict tests and a staff journey that assigns, moves, swaps, and releases resources without double allocation.

### RG-4.3 — Reservations and CRM

- Create manual reservations originating from email or WhatsApp with guest, program, dates, and companions.
- Track commercial proposals before confirmation.
- Preserve repeat-guest stay history, preferences, restrictions, and referral/source information.

Acceptance requires server-priced quotes, atomic inventory commit, immutable price/policy snapshots, guarded amendments, and a readable reservation hub and change ledger.

### RG-4.4 — Communication automation

- Provide versioned templates for confirmation, payment instructions, pre-arrival recommendations, arrival instructions, thanks, and post-stay survey requests.
- Trigger the correct communication from reservation events and time-based milestones.
- Notify guides, kitchen, hosts, and operations with only the information required for their work.
- Generate internal pre-arrival reminders and checklists.

Acceptance requires production delivery evidence, provider delivery/bounce/complaint state, suppression and consent, visible failures, safe replay, and duplicate-safe scheduling.

### RG-4.5 — Payments and billing

- Track deposit and balance deadlines, including the current 50% deposit and balance 30 days before arrival policy as configurable policy rather than hard-coded behavior.
- Record international bank-transfer payments and attach/approve evidence.
- Support USD and ARS price books without duplicating product data.

The brief names bank transfer, not card processing, as the required launch rail. Online payment is an additional product requirement from the later implementation discussion and must be delivered through one selected provider using hosted checkout, signed webhooks, provider-backed refunds, receipts, and settlement reconciliation. Inn must never store raw card data.

### RG-4.6 — Financial dashboard

- Report reservation volume, occupancy, and programs sold by period.
- Report revenue, defined costs, and margin by program.
- Report commission by source/channel.

Acceptance requires client-approved KPI definitions and downloadable tenant-scoped CSV/XLSX outputs whose totals reconcile to the underlying ledger.

### RG-4.7 — Kitchen and dietary restrictions

- Record guest allergies, diets, and preferences.
- Give kitchen an aggregated date-based view without granting access to unrelated guest or financial data.

Acceptance requires field-level role tests and a state-changing journey from reservation/guest update to the kitchen projection.

### RG-4.8 — Operational tasks by group/program

- Generate tasks appropriate to the booked program.
- Generate editable standard checklists by role and allow reservation-specific adjustments.

Acceptance requires exact-once generation, assignee visibility, overdue/failure handling, completion history, and cancellation/amendment effects.

### RG-4.9 — Extras and final account

- Post in-stay services or consumption against the reservation.
- Present one consolidated account containing base price, extras, adjustments, payments, fees, refunds, and final balance.

Acceptance requires check-in through checkout, immutable correction entries, a zero-balance folio close, and downloadable final documents.

### RG-4.10 — Survey and experience closure

- Send a post-checkout survey request automatically.
- Track responses for authorized staff.

Acceptance requires a production-delivered invitation, a guest response, read-only staff tracking, consent/suppression behavior, and duplicate-safe scheduling.

### RG-4.11 — Roles and permissions

- Administrator: full access.
- Sales/operations: reservations, calendar, operational preparation, and the explicitly assigned payment actions.
- Guide: own availability and assignments only.
- Kitchen: dietary and preparation information only.
- Owner: financial reporting without operational mutation.
- Finance: payment evidence, reconciliation, refunds, receipts, and exports according to separation-of-duties rules.

Acceptance requires allow/deny tests for every new action, query, export, file download, provider event, and tenant boundary.

## Derived release requirements

These are engineering requirements necessary to make RG-4.1 through RG-4.11 safe and operable. They do not expand the business scope into a generic ERP.

| ID | Requirement |
| --- | --- |
| RG-X1 | Integer minor-unit money, explicit ISO currency, deterministic rounding, and immutable source snapshots. |
| RG-X2 | Half-open allocation intervals, commit-time availability checks, database locking, and no calendar-side inventory authority. |
| RG-X3 | Idempotency plus database uniqueness for every retried money-changing, provider, scheduling, document, and sync command. |
| RG-X4 | Append-only financial corrections and reservation changes; completed documents and events are never rewritten. |
| RG-X5 | Private tenant-scoped storage, authorized downloads, upload validation/scanning, retention, and privacy deletion/export procedures. |
| RG-X6 | Observable queues, schedules, external deliveries, integrations, retries, dead letters, replay, and reconciliation. |
| RG-X7 | Deterministic PostgreSQL fixtures, unit/domain tests, integration/contract tests, and state-changing Playwright release journeys. |
| RG-X8 | Production deployment, secrets, monitoring, backups, restore rehearsal, rollback, incident handling, and operational handoff. |

## Explicit non-assumptions

- A model, settings screen, adapter interface, healthy service, or source inspection does not make a capability client-complete.
- A manual payment record is not a card capture, and an internal refund completion is not a provider refund.
- A one-way iCalendar feed is not a channel manager.
- A folio is not automatically a jurisdiction-compliant tax invoice.
- Frappe Payments, an ERP, a Filament plugin, or an external demo cannot become a second reservation/payment source of truth.
- Multi-property central reservations, restaurant-grade POS, door locks, loyalty, gift cards, automated revenue management, and marketplace breadth are outside this single-lodge baseline unless separately contracted.
