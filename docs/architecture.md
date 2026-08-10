# Architecture

## Decision summary

LodgeOps is a modular monolith. Laravel is the sole owner of domain rules, authorization, transactions, audit history, pricing, availability, and persistence. Filament and Next.js are delivery adapters over the same application layer.

This avoids two sources of truth while preserving a purpose-built calendar and mobile workflow that CRUD-oriented administration screens cannot provide well.

## Runtime topology

- `app.example.com`: Next.js staff and guest application.
- `api.example.com`: versioned Laravel JSON API.
- `ops.example.com`: Filament administration, using the Laravel web session.
- PostgreSQL stores canonical state; Redis backs queues, cache, locks, and sessions; object storage holds tenant-prefixed private files.
- Slow or external work is queued after database commit. The scheduler runs in UTC and dispatches tenant-local jobs.

All three application hosts must share a registrable domain so first-party browser authentication can use Sanctum cookie sessions with CSRF protection. Bearer tokens are reserved for explicit integrations and are never stored in browser local storage.

## Multi-tenancy

The initial deployment uses a shared database with strict row ownership:

- global users join tenants through active memberships;
- every tenant-owned row has a non-null `tenant_id`;
- tenant IDs are derived from trusted route/host context and an authenticated membership, never accepted from request bodies;
- models use a tenant scope, while policies and service methods independently enforce membership and role;
- important uniqueness rules and relationships include `tenant_id` at the database layer;
- queue jobs, cache keys, locks, broadcasts, exports, search, and storage paths carry an explicit tenant key;
- tests create two tenants and prove list, show, create, update, delete, search, export, file, queue, and Filament isolation.

PostgreSQL row-level security can be added as defense in depth after request and worker connection context is guaranteed to be transaction-local. Application policies remain mandatory.

## Domain modules

1. Identity: tenants, properties, memberships, roles, invitations, branding, audit.
2. CRM: guests, organizations, agencies, preferences, dietary requirements, timeline.
3. Sales: inquiries, opportunities, proposal versions, options, holds, conversion.
4. Catalog: programs, itineraries, activities, seasons, rate plans, taxes, policies.
5. Inventory: resources, capabilities, pools, blocks, occurrences, requirements, allocations.
6. Reservations: party, stays, booking items, status history, travel, waivers.
7. Money: folios, immutable lines, deposit schedules, manual payments, evidence, refunds, FX snapshots.
8. Operations: task templates, generated checklists, kitchen forecast, housekeeping, incidents.
9. Communications: versioned templates, automation rules, outbox, delivery attempts, consent.
10. Closeout: extras, documents, surveys, costs, commission, owner reporting.

## Critical invariants

- Store instants in UTC and display them in the property IANA timezone.
- Use half-open intervals `[start, end)` so same-day room turnover is valid.
- Money uses integer minor units and an ISO 4217 currency code, never floating point.
- Exclusive resources cannot overlap active holds, allocations, or blocks.
- Pool allocations cannot exceed capacity; buyouts reserve the whole property and conflict with every allocation in their window.
- Holds expire and are released idempotently.
- Reservation, payment, and readiness state are separate state machines.
- Confirmed prices, sent proposals, posted financial records, rendered communications, and audit events are immutable snapshots.
- Every retryable command has a tenant-scoped idempotency key.
- Every sensitive write and file access is audited.

## Experience split

Filament owns platform provisioning, tenant configuration, resources, programs, rate plans, templates, integrations, payment reconciliation, users, roles, costs, commissions, imports, exports, and exception queues.

Next.js owns the master calendar, reservation composer, proposal workspace, arrival board, assignment suggestions, task board, kitchen and housekeeping views, guide mobile view, owner dashboard, notification center, and branded guest portal.

Business rules are never implemented only in Filament callbacks or Client Components. Both call Laravel actions guarded by policies and transactions.

## Source references

The version choices and architecture follow current official documentation:

- Laravel 13 release and support policy: https://laravel.com/docs/13.x/releases
- Laravel Sanctum SPA authentication: https://laravel.com/docs/13.x/sanctum
- Laravel queues and scheduler: https://laravel.com/docs/13.x/queues and https://laravel.com/docs/13.x/scheduling
- Filament 5 installation, tenancy, and security: https://filamentphp.com/docs/5.x/introduction/installation, https://filamentphp.com/docs/5.x/users/tenancy, https://filamentphp.com/docs/5.x/advanced/security
- Next.js 16 App Router and data security: https://nextjs.org/docs/app and https://nextjs.org/docs/app/guides/data-security

