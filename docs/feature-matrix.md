# Product traceability matrix

This matrix is the definition of product coverage. A capability is complete only when its tenant boundary, role permissions, acceptance behavior, and automated tests are present.

| Area | Required outcome | Primary experience | Status and automated evidence |
| --- | --- | --- | --- |
| Master calendar | Rooms, programs, activities and shared resources in resource lanes and a mobile agenda, with 7/14/30-day navigation, program labels, blocks and operational lenses | Filament + Livewire custom page | Implemented — `FilamentWorkspacePagesTest`, `StaffProjectionTest`, `AllocationConflictTest` |
| Resource planning | Guide skills/languages/ratios plus horses, boats and vehicles with suggestions and hard conflicts | Filament | Implemented — `ResourceSuggestionTest`, `ResourceSuggestionFilamentTest`, `AllocationConflictTest`, `FilamentResourcesTest` |
| Reservations | Manual entry, guests/companions, itinerary, stays, activities, flags, notes, copy/amend/cancel, actual arrival/departure facts and reasoned cancellation/no-show closure | Filament | Implemented — `ReservationApiTest`, `ReservationStatusTest`, `FilamentResourcesTest` |
| CRM and sales | Repeat guest history, preferences, agency/channel, inquiry pipeline, proposal versions and conversion | Filament | Implemented — `CommercialWorkflowTest`, `ExtendedOperationsTest`, `FilamentResourcesTest` |
| Communications | Versioned email/SMS templates, confirmation/payment/pre-arrival/post-stay automations, internal alerts | Filament + queues | Implemented — `OutboxAfterCommitTest`, `OutboxAutomationTest`, `OutboxBatchPublisherTest`, `ExtendedOperationsTest` |
| Payments | Deposit schedule, 50% default, balance 30 days prior, manual bank transfer and evidence | Filament + Laravel guest portal | Implemented — `PaymentApiTest`, `CommercialWorkflowTest`, `GuestPortalTest`, `GuestPortalWebTest` |
| Multi-currency | USD/ARS price books and property-aware exchange-rate snapshots without duplicate catalog data | Filament | Implemented — `MoneyCalculatorTest`, `FinanceReportingTest`, `ExtendedOperationsTest` |
| Financial dashboard | Selectable reporting periods, raw currency totals, explicit FX consolidation, revenue, costs, margin, receivables and commission | Filament custom page | Implemented — `FinanceReportingTest`, `FilamentWorkspacePagesTest`, `StaffProjectionTest`, `ExtendedOperationsTest` |
| Kitchen | Guest counts and restricted dietary summary for a selectable property-local planning range without unrelated identity access | Filament custom page | Implemented — `FilamentKitchenDashboardTest`, `FilamentWorkspacePagesTest`, `StaffProjectionTest` role and field-redaction matrix |
| Operational tasks | Program-generated checklists by role with arrival readiness and escalation | Filament | Implemented — `FilamentWorkspacePagesTest`, `OutboxAutomationTest`, `StaffProjectionTest` |
| Extras and final folio | In-stay extras, discounts, adjustments, gratuity and consolidated account | Filament + Laravel guest portal | Implemented — `CommercialWorkflowTest`, `GuestPortalTest`, `GuestPortalWebTest` |
| Surveys | Automatic post-checkout invitation plus filterable, read-only staff response tracking | Laravel guest portal + Filament | Implemented — `GuestPortalTest`, `GuestPortalWebTest`, `FilamentSurveyResponseTrackerTest` |
| Roles | Full Administrator, finance-read-only Owner, manager, sales/operations, guide, kitchen, housekeeping and finance workspaces | Filament | Implemented — `RoleSemanticsTest`, `TenantIsolationTest`, `FilamentTenancyTest`, `FilamentRelatedPropertyScopeTest`, `FilamentResourcesTest` |
| Guest portal | Secure itinerary, pre-registration, companion details, travel, waivers, evidence and survey | Laravel | Implemented — `GuestPortalTest`, `GuestPortalWebTest`, `DatabaseSeederTest` |
| Documents | Versioned templates, invoices, itinerary, attachments and signature adapters | Filament + portal | Implemented — `ExtendedOperationsTest`, `GuestPortalTest` |
| Retail/POS | Optional catalog, stock movement, tablet sale and reservation-linked charges | Filament | Implemented — `ExtendedOperationsTest` transactional stock-and-folio invariants |
| Payroll/costs | Guide/staff cost records and commission accruals | Filament | Implemented — `ExtendedOperationsTest` |
| Reporting | Role dashboards, explicit definitions, date/property filters, FX policy, CSV/PDF export | Filament | Implemented — `FinanceReportingTest`, `FilamentWorkspacePagesTest`, `StaffProjectionTest`, `ExtendedOperationsTest` CSV-injection protection |
| Integrations | Email/calendar/accounting/payment/e-sign/webhook adapters plus a production-safe Mews connection health boundary | Filament + API | Implemented adapter boundary — `MewsIntegrationTest`, `ExtendedOperationsTest`, outbox suites; provider synchronization requires tenant-owned credentials and source-of-truth mapping |

## Simplifications over the legacy product

- A single configurable booking flow replaces separate room, group, guide, and special-rate variants.
- Calendar lenses replace independent room, guide, combo, season, and summary products.
- A generalized resource and allocation model replaces unrelated availability mechanisms.
- The CRM unifies contacts, guests, groups, agencies, and communication history.
- Tasks and automation unify reminders, processes, checklists, and preparation logs.
- The document center unifies file management, PDFs, images, attachments, and waivers.
- A secure guest portal replaces surname/date lookups, sequential public IDs, and per-tenant custom source code.
