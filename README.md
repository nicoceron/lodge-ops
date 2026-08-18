# Inn

Inn is a multi-tenant operating system for lodges and outfitters. It coordinates sales, reservations, accommodations, guides, shared resources, guest preparation, kitchen operations, payments, communications, tasks, and reporting in one system.

The product intentionally improves on the legacy LodgeRunner workflow instead of reproducing its duplicate room/group/guide booking variants:

- one reservation composer;
- one constraint-aware allocation engine for property-defined places, assets, and crew;
- one unified calendar with role-specific lenses;
- separate sales, reservation, payment, and fulfillment states;
- secure tenant-scoped API and opaque public links;
- Laravel and Filament as one tenant-aware staff application, with a Laravel-rendered guest stay center and a separate public-only Next.js website.

## Stack

- Laravel 13 / PHP 8.5: domain rules, API, guest portal, authorization, queues, automation
- Filament 5 / Livewire: the complete tenant-aware staff workspace
- Next.js 16 / React 19: public homepage, product, pricing, and security content only
- PostgreSQL 18, Redis 8, Mailpit, Docker Compose
- PHPUnit with HTTP, Livewire, Filament resource, tenant-isolation, and contract coverage

## Local development

Requirements: Docker with Compose. Local PHP 8.5 and Node 24 are optional for faster test runs.

```bash
make bootstrap
make up
make doctor
```

`make up` starts the complete stack in the background and waits for the public website and Laravel health checks. Use `make logs` to follow the public site, application, worker, and scheduler logs, and `make down` to stop containers without deleting database or upload volumes.

- Public website: http://localhost:3000
- Staff experience and sign-in: http://localhost:8000/manage
- Laravel application and versioned API: http://localhost:8000 and http://localhost:8000/api/v1
- Local email inbox: http://localhost:8025
- Guest preview: http://localhost:8000/guest/access/g_7JvK2pQ9xR4mN8tW3cD6hF1sB5yE0uA

The deterministic development seed creates `admin@example.com` / `password` for tenant `11111111-1111-4111-8111-111111111111` and a resettable, one-time guest preview link. Filament never pre-fills credentials; normal local Compose runs use persisted PostgreSQL data.

The seed also includes a bounded seven-month history of bookings, collections, unpaid balances, programs, category-only requests, assigned instances, housekeeping states, closed folios, note timelines, and a private channel calendar feed so every major workflow is demonstrable instead of appearing as an empty shell.

Run the complete formatter, contract, HTTP, Filament, domain, public build, and browser suite with:

```bash
make verify
```

With the seeded Compose application running, execute the authenticated client-UAT gate separately:

```bash
make test-client
```

Run the isolated PostgreSQL document/export gate with:

```bash
make test-documents-exports
```

If ports 5432 or 6379 are already occupied, Compose supports explicit alternatives:

```bash
POSTGRES_PORT=55432 REDIS_PORT=56379 make up
```

The backend suite is also exercised against PostgreSQL in CI; SQLite remains a fast local feedback path.

## What is implemented

- A responsive Filament staff workspace for the unified calendar, reservations, CRM, operations, kitchen, finance, and configuration.
- A responsive Livewire resource planner with week, two-week, and 30-day windows; place/asset/crew lanes; category-level requests; exact assignments; availability blocks; operational lenses; and seeded stays, activities, and tasks.
- A public-only Next.js website that links directly to Filament for sign-in and contains no tenant session, API proxy, or protected staff routes.
- A Laravel-rendered mobile-first guest reservation center with itinerary, pre-arrival data, consent, waivers, payment evidence, final folio, and post-stay survey flows.
- Native Filament authentication with verified-email enforcement, password recovery, TOTP/recovery-code MFA, explicit tenant selection, role-separated permissions, database-enforced tenant relationships, and Sanctum bearer tokens for explicit API integrations.
- Transaction-safe reservation confirmation, category and instance capacity checks, half-open intervals, holds/status transitions, actual check-in/check-out facts, housekeeping handoff, reasoned cancellation/no-show closure, optimistic revisions, minor-unit money, net/tax/gross folios, close/reopen controls, and retry-safe commands.
- Availability-first staff booking with deterministic server-priced quotes, immutable rate/tax/deposit/cancellation snapshots, inline or repeat guests, and atomic category or exact-room holds.
- A controlled manual bank-transfer loop from guest evidence through authorized staff review to idempotent payment, folio, and deposit reconciliation.
- Guarded quote-authoritative amendments, room assignment/move/swap, policy-priced cancellation/no-show, append-only partial/full internal refunds, and a readable reservation change ledger.
- Asynchronous reservation confirmations, itineraries, folio statements, payment/refund receipts, and waiver copies rendered from immutable canonical snapshots and trusted versioned templates; artifacts remain private, checksummed, replacement-linked, and policy-authorized for staff/guest download or email intent.
- Asynchronous CSV/XLSX arrivals, departures, occupancy, revenue, payment/deposit/refund, cost/commission, dietary, and task/housekeeping exports with property-local filters, formula neutralization, role-scoped definitions, integrity metadata, retry state, seven-day default expiry, and ledger-preserving purge.
- Tenant-aware Filament resources and custom Livewire pages for day-to-day operations, commercial workflows, finance, inventory, communications, documents, integrations, and exception work.
- After-commit outbox delivery with tenant-context restoration, retries, observable failures, and idempotent automation actions for tasks, communications, and deposit reminders.
- A checked-in OpenAPI contract, deterministic fixtures, Docker runtime, and CI gates spanning Laravel, Filament, tenant isolation, the complete guest workflow, and public-site browser journeys.

## Repository map

- `apps/api`: the Laravel application, Filament panel, guest portal, and versioned API
- `apps/web`: public-only Next.js marketing website
- `contracts`: versioned HTTP API contract
- `docs`: product traceability, architecture, security, and operations
- `docker`: reproducible runtime images

The source scrape used for product research is deliberately outside this repository and is never copied into builds, fixtures, or Git history.

## Document and export operations

The Compose worker consumes `critical,documents,automations,default,notifications,integrations,reports`; production supervision must preserve the `documents` and `reports` queues. The scheduler runs `artifacts:purge-expired` daily. Purging deletes expired private objects but retains request/export rows and audit metadata.

Configuration is environment-driven:

- `DOCUMENT_DISK` selects the private Laravel filesystem disk.
- `DOCUMENT_RENDERER` selects the registered trusted renderer (`dompdf` by default).
- `DOCUMENT_PDFINFO_BINARY` selects the Poppler parser used to reject malformed renderer output before storage.
- `DOCUMENT_EXPORT_TTL_DAYS` controls report retention (seven days by default).
- `DOCUMENT_JOB_*` and `DOCUMENT_EXPORT_JOB_*` control queue names, timeouts, attempts, retry windows, and overlap-lock expiry.

Inspect artifacts through the authorized Filament, API, or guest download flow; raw storage paths are deliberately absent from UI and API responses. For local format diagnostics, `make test-documents-exports` creates temporary artifacts on the isolated `inn_test` database and verifies PDF parsing/rendering plus CSV/XLSX parsing without refreshing the demo database.

If generation fails, inspect the redacted failure in **Document Generation Requests** or **Report Exports**, then check `make logs` for the request/export UUID. Confirm that the worker consumes the correct queue, the private disk is writable, Poppler is installed, and the configured template is active before using Retry. Do not log source snapshots, guest tokens, attachment paths, or document contents.

QloApps receipt/voucher behavior informed domain review only. No QloApps OSL-3.0 source was copied; Inn's snapshots, storage, authorization, queueing, and templates are original implementation.
