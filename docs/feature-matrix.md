# Product traceability matrix

This matrix is the definition of product coverage. A capability is complete only when its tenant boundary, role permissions, acceptance behavior, and automated tests are present.

| Area | Required outcome | Primary experience | Verification focus |
| --- | --- | --- | --- |
| Master calendar | Rooms, programs, activities and shared resources in one date grid, program labels, buyouts, saved lenses | Next | overlap, capacity, keyboard view, timezone |
| Resource planning | Guide skills/languages/ratios plus horses, boats and vehicles with suggestions and hard conflicts | Next + Filament | qualification, ratio, fairness, double assignment |
| Reservations | Manual entry, guests/companions, itinerary, stays, activities, flags, notes, copy/amend/cancel | Next | lifecycle, audit, idempotency, tenant isolation |
| CRM and sales | Repeat guest history, preferences, agency/channel, inquiry pipeline, proposal versions and conversion | Next + Filament | immutable sent version, dedupe, follow-up |
| Communications | Versioned email/SMS templates, confirmation/payment/pre-arrival/post-stay automations, internal alerts | Filament + queues | after-commit, retry, suppression, no duplicates |
| Payments | Deposit schedule, 50% default, balance 30 days prior, manual bank transfer and evidence | Next + Filament | integer money, partial/overpayment, immutable posting |
| Multi-currency | USD/ARS price books and exchange-rate snapshots without duplicate catalog data | Filament | rounding, historical snapshot, mixed-currency rejection |
| Financial dashboard | Occupancy, program revenue, costs, margin, receivables and channel commission | Next owner view | reconciliation to transactional data |
| Kitchen | Guest counts and restricted dietary summary by date without unrelated data access | Next role view | field-level permission, totals, change propagation |
| Operational tasks | Program-generated checklists by role with arrival readiness and escalation | Next + Filament | deterministic generation, rebasing, due dates |
| Extras and final folio | In-stay extras, discounts, adjustments, gratuity and consolidated account | Next | line totals, reversals, checkout readiness |
| Surveys | Automatic post-checkout invitation and response tracking | Guest portal + Filament | signed token, expiry, completion |
| Roles | Admin, sales/operations, guide, kitchen, housekeeping, finance and owner workspaces | All | deny-by-default matrix and sensitive fields |
| Guest portal | Secure itinerary, pre-registration, companion details, travel, waivers, evidence and survey | Next | opaque expiring link, IDOR, upload controls |
| Documents | Versioned templates, invoices, itinerary, attachments and signature adapters | Filament + portal | private storage, signed URL, audit |
| Retail/POS | Optional catalog, stock movement, tablet sale and reservation-linked charges | Filament/Next module | feature flag, stock and folio consistency |
| Payroll/costs | Guide/staff cost records and commission accruals | Filament | separation from guest payments, owner visibility |
| Reporting | Role dashboards, explicit definitions, filters, CSV/PDF export | Next + Filament | tenant-scoped export and formula injection |
| Integrations | Email/calendar/accounting/payment/e-sign/webhook adapters | Filament | secret isolation, signatures, retries, backfill |

## Simplifications over the legacy product

- A single configurable booking flow replaces separate room, group, guide, and special-rate variants.
- Calendar lenses replace independent room, guide, combo, season, and summary products.
- A generalized resource and allocation model replaces unrelated availability mechanisms.
- The CRM unifies contacts, guests, groups, agencies, and communication history.
- Tasks and automation unify reminders, processes, checklists, and preparation logs.
- The document center unifies file management, PDFs, images, attachments, and waivers.
- A secure guest portal replaces surname/date lookups, sequential public IDs, and per-tenant custom source code.

