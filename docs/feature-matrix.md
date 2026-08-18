# Product traceability matrix

This matrix traces implemented domain coverage. It does not by itself establish that a client journey or external provider integration is complete. Client-facing completion requires an executable authenticated journey and is tracked in [the client-readiness implementation gap map](client-readiness-implementation-gap-map.md). The ordered build sequence is in [the client-ready implementation plan](client-ready-implementation-plan.md).

| Area | Required outcome | Primary experience | Status and automated evidence |
| --- | --- | --- | --- |
| Master calendar | Property-defined places, assets and crew in resource lanes and a mobile agenda, with 7/14/30-day navigation, program labels, blocks and operational lenses | Filament + Livewire custom page | Implemented — `FilamentWorkspacePagesTest`, `StaffProjectionTest`, `AllocationConflictTest` |
| Resource planning | Property-owned categories, requested-category holds, exact-instance assignment, capability/language constraints, suggestions, buyouts and hard conflicts | Filament + API | Implemented — `GenericOperationalFlowsTest`, `ResourceSuggestionTest`, `ResourceSuggestionFilamentTest`, `AllocationConflictTest`, `FilamentResourcesTest` |
| Reservations | Availability-first staff composition, live category/exact-room fit, immutable nightly/service/tax quote, inline/repeat guests and companions, atomic holds/allocations, itinerary, lifecycle, notes, and an operational reservation hub | Filament + API | Client-complete staff hold slice — `UAT-4.3`, `ClientBookingCoreTest`, `FilamentResourcesTest`; controlled re-quote/amend/move/refund commands remain follow-on work |
| CRM and sales | Repeat guest history, preferences, agency/channel, inquiry pipeline, proposal versions and conversion | Filament | Implemented — `CommercialWorkflowTest`, `ExtendedOperationsTest`, `FilamentResourcesTest` |
| Communications | Versioned email/SMS templates, confirmation/payment/pre-arrival/post-stay automations, internal alerts | Filament + queues | Domain-complete for email automation; SMS/WhatsApp providers, delivery events and inbound handling are not implemented |
| Payments | Configurable fixed/percentage deposits, balance offsets, manual bank transfer evidence, secure staff review, and exact-once payment/folio/deposit reconciliation | Filament + Laravel guest portal | Client-complete manual-transfer slice — `UAT-4.5`, `PaymentEvidenceReviewTest`; online checkout, provider webhooks, refunds, and settlement reconciliation remain provider/follow-on work |
| Multi-currency | USD/ARS price books and property-aware exchange-rate snapshots without duplicate catalog data | Filament | Implemented — `MoneyCalculatorTest`, `FinanceReportingTest`, `ExtendedOperationsTest` |
| Financial dashboard | Selectable reporting periods, raw currency totals, explicit FX consolidation, revenue, costs, margin, receivables and commission | Filament custom page | Implemented — `FinanceReportingTest`, `FilamentWorkspacePagesTest`, `StaffProjectionTest`, `ExtendedOperationsTest` |
| Kitchen | Guest counts and restricted dietary summary for a selectable property-local planning range without unrelated identity access | Filament custom page | Implemented — `FilamentKitchenDashboardTest`, `FilamentWorkspacePagesTest`, `StaffProjectionTest` role and field-redaction matrix |
| Operational tasks | Program-generated checklists by role with arrival readiness, housekeeping state and automatic dirty-on-checkout handoff | Filament + API | Implemented — `GenericOperationalFlowsTest`, `ReservationStatusTest`, `FilamentWorkspacePagesTest`, `OutboxAutomationTest`, `StaffProjectionTest` |
| Extras and final folio | In-stay extras, immutable net/tax/gross lines, reversals, settlement, open/closed lifecycle and consolidated account | Filament + Laravel guest portal + API | Implemented — `GenericOperationalFlowsTest`, `CommercialWorkflowTest`, `GuestPortalTest`, `GuestPortalWebTest` |
| Surveys | Automatic post-checkout invitation plus filterable, read-only staff response tracking | Laravel guest portal + Filament | Implemented — `GuestPortalTest`, `GuestPortalWebTest`, `FilamentSurveyResponseTrackerTest` |
| Roles | Full Administrator, finance-read-only Owner, manager, sales/operations, guide, kitchen, housekeeping and finance workspaces | Filament | Implemented — `RoleSemanticsTest`, `TenantIsolationTest`, `FilamentTenancyTest`, `FilamentRelatedPropertyScopeTest`, `FilamentResourcesTest` |
| Guest portal | Secure itinerary, pre-registration, companion details, travel, waivers, declared transfer evidence with review state, final folio, and survey | Laravel | Implemented — `GuestPortalTest`, `GuestPortalWebTest`, `PaymentEvidenceReviewTest`, `DatabaseSeederTest` |
| Documents | Versioned templates, invoices, itinerary, attachments and signature adapters | Filament + portal | Scaffold/domain coverage — production PDF rendering, invoice issuance/download and provider e-signature lifecycle remain incomplete |
| Retail/POS | Optional catalog, stock movement, tablet sale and reservation-linked charges | Filament | Implemented — `ExtendedOperationsTest` transactional stock-and-folio invariants |
| Payroll/costs | Guide/staff cost records and commission accruals | Filament | Implemented — `ExtendedOperationsTest` |
| Reporting | Role dashboards, explicit definitions, date/property filters, FX policy, CSV/PDF export | Filament | Domain-complete dashboards; runtime CSV/PDF generation and authorized download are not implemented |
| Integrations | Email/calendar/accounting/payment/e-sign/webhook adapters plus private per-resource iCalendar channel feeds | Filament + API | One-way calendar feed and local email transport are real; the remaining entries are configuration scaffolds without provider clients, sync, retry or reconciliation |

## Simplifications over the legacy product

- A single configurable booking flow replaces separate room, group, guide, and special-rate variants.
- Calendar lenses replace independent room, guide, combo, season, and summary products.
- A generalized resource and allocation model replaces unrelated availability mechanisms.
- The CRM unifies contacts, guests, groups, agencies, and communication history.
- Tasks and automation unify reminders, processes, checklists, and preparation logs.
- The document center unifies file management, PDFs, images, attachments, and waivers.
- A secure guest portal replaces surname/date lookups, sequential public IDs, and per-tenant custom source code.
