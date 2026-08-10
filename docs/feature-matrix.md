# Product traceability matrix

This matrix is the definition of product coverage. A capability is complete only when its tenant boundary, role permissions, acceptance behavior, and automated tests are present.

| Area | Required outcome | Primary experience | Status and automated evidence |
| --- | --- | --- | --- |
| Master calendar | Rooms, programs, activities and shared resources in one date grid, program labels, buyouts, saved lenses | Next | Implemented — `StaffProjectionTest`, `AllocationConflictTest`, `master-calendar.test.tsx`, Playwright navigation |
| Resource planning | Guide skills/languages/ratios plus horses, boats and vehicles with suggestions and hard conflicts | Next + Filament | Implemented — `ResourceSuggestionTest`, `AllocationConflictTest` |
| Reservations | Manual entry, guests/companions, itinerary, stays, activities, flags, notes, copy/amend/cancel | Next | Implemented — `ReservationApiTest`, `ReservationStatusTest`, `StaffProjectionTest` |
| CRM and sales | Repeat guest history, preferences, agency/channel, inquiry pipeline, proposal versions and conversion | Next + Filament | Implemented — `CommercialWorkflowTest`, `ExtendedOperationsTest` |
| Communications | Versioned email/SMS templates, confirmation/payment/pre-arrival/post-stay automations, internal alerts | Filament + queues | Implemented — `OutboxAfterCommitTest`, `OutboxAutomationTest`, `OutboxBatchPublisherTest`, `ExtendedOperationsTest` |
| Payments | Deposit schedule, 50% default, balance 30 days prior, manual bank transfer and evidence | Next + Filament | Implemented — `PaymentApiTest`, `CommercialWorkflowTest`, `GuestPortalTest` |
| Multi-currency | USD/ARS price books and exchange-rate snapshots without duplicate catalog data | Filament | Implemented — `MoneyCalculatorTest`, `ExtendedOperationsTest` |
| Financial dashboard | Occupancy, program revenue, costs, margin, receivables and channel commission | Next owner view | Implemented — `StaffProjectionTest`, `ExtendedOperationsTest` |
| Kitchen | Guest counts and restricted dietary summary by date without unrelated data access | Next role view | Implemented — `StaffProjectionTest` role and field-redaction matrix |
| Operational tasks | Program-generated checklists by role with arrival readiness and escalation | Next + Filament | Implemented — `OutboxAutomationTest`, `StaffProjectionTest` |
| Extras and final folio | In-stay extras, discounts, adjustments, gratuity and consolidated account | Next | Implemented — `CommercialWorkflowTest`, `GuestPortalTest` |
| Surveys | Automatic post-checkout invitation and response tracking | Guest portal + Filament | Implemented — `GuestPortalTest`, guest Playwright journey |
| Roles | Admin, sales/operations, guide, kitchen, housekeeping, finance and owner workspaces | All | Implemented — `TenantIsolationTest`, `FilamentTenancyTest`, `FilamentResourcesTest`, `StaffProjectionTest` |
| Guest portal | Secure itinerary, pre-registration, companion details, travel, waivers, evidence and survey | Next | Implemented — `GuestPortalTest`, `DatabaseSeederTest`, guest component tests and Playwright journey |
| Documents | Versioned templates, invoices, itinerary, attachments and signature adapters | Filament + portal | Implemented — `ExtendedOperationsTest`, `GuestPortalTest` |
| Retail/POS | Optional catalog, stock movement, tablet sale and reservation-linked charges | Filament/Next module | Implemented — `ExtendedOperationsTest` transactional stock-and-folio invariants |
| Payroll/costs | Guide/staff cost records and commission accruals | Filament | Implemented — `ExtendedOperationsTest` |
| Reporting | Role dashboards, explicit definitions, filters, CSV/PDF export | Next + Filament | Implemented — `StaffProjectionTest`, `ExtendedOperationsTest` CSV-injection protection |
| Integrations | Email/calendar/accounting/payment/e-sign/webhook adapters | Filament | Implemented adapter boundary — `ExtendedOperationsTest`, outbox suites; provider activation requires tenant-owned credentials |

## Simplifications over the legacy product

- A single configurable booking flow replaces separate room, group, guide, and special-rate variants.
- Calendar lenses replace independent room, guide, combo, season, and summary products.
- A generalized resource and allocation model replaces unrelated availability mechanisms.
- The CRM unifies contacts, guests, groups, agencies, and communication history.
- Tasks and automation unify reminders, processes, checklists, and preparation logs.
- The document center unifies file management, PDFs, images, attachments, and waivers.
- A secure guest portal replaces surname/date lookups, sequential public IDs, and per-tenant custom source code.
