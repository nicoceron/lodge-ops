# Architecture

## Decision summary

Inn is a modular monolith. Laravel is the sole owner of authentication, domain rules, authorization, transactions, audit history, pricing, availability, persistence, and the secure guest workflow. Filament is the tenant-aware staff product UI over that application layer. Next.js is a separate public marketing site only.

Filament resources handle record-centric workflows; Filament custom Livewire pages handle the master calendar, live operations board, and finance dashboard. Shared calendar, dashboard, operations, and finance projections live in application services so Filament and JSON controllers never invoke one another. The guest stay center uses server-rendered Laravel views and the same hardened guest-portal actions as the JSON API. The Next.js site has no login implementation, protected routes, tenant selection, API proxy, Sanctum state, or guest magic-link workflow.

## Runtime topology

- `www.example.com`: public-only Next.js marketing website.
- `app.example.com`: Laravel application, Filament staff panel at `/manage`, guest stay center, and versioned JSON API.
- PostgreSQL stores canonical state; Redis backs queues, cache, locks, and sessions; object storage holds tenant-prefixed private files.
- Slow or external work is queued after database commit. The scheduler runs in UTC and dispatches tenant-local jobs.

Filament uses Laravel's encrypted web session and CSRF protection. Guest magic links are exchanged once for a server-side encrypted session value; raw session tokens are never rendered into HTML or stored in browser local storage. Bearer tokens remain available for explicit API integrations.

## Multi-tenancy

The initial deployment uses a shared database with strict row ownership:

- global users join tenants through active memberships;
- Filament identifies the active tenant in `/manage/workspace/{tenant-slug}/...` and verifies that the signed-in user has an active membership before resolving the application tenant context;
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
5. Inventory: property-defined place/asset/crew categories, instances, capabilities, pools, blocks, occurrences, requirements, requested categories, and exact allocations.
6. Reservations: party, stays, booking items, append-only status and note timelines, travel, waivers.
7. Money: open/closed folios, immutable net/tax/gross lines, deposit schedules, manual payments, evidence, refunds, FX snapshots.
8. Operations: task templates, generated checklists, kitchen forecast, place housekeeping lifecycle, incidents.
9. Communications: versioned templates, automation rules, outbox, delivery attempts, consent.
10. Closeout: extras, documents, surveys, costs, commission, owner reporting.

## Critical invariants

- Store instants in UTC and display them in the property IANA timezone.
- Use half-open intervals `[start, end)` so a resource can turn over at the exact boundary without a false conflict.
- Money uses integer minor units and an ISO 4217 currency code, never floating point.
- Exclusive resources cannot overlap active holds, allocations, or blocks.
- Pool allocations cannot exceed capacity; buyouts reserve the whole property and conflict with every allocation in their window.
- Holds expire and are released idempotently.
- Reservation, payment, and readiness state are separate state machines.
- Confirmed prices, sent proposals, posted financial records, rendered communications, and audit events are immutable snapshots.
- Every retryable command has a tenant-scoped idempotency key.
- Every sensitive write and file access is audited.

## Application surfaces

Filament owns the complete authenticated staff experience: calendar, reservation composer, CRM, operations board, task queues, guide/kitchen/housekeeping views, finance dashboard, configuration, integrations, reconciliation, users, imports, exports, and exception work.

Laravel owns the public guest stay center: one-time magic-link exchange, itinerary, pre-arrival profile, document acknowledgement, payment evidence, folio, survey, and secure logout. The web and JSON controllers call the same application service rather than invoking one another or duplicating domain behavior.

Next.js owns only public-facing marketing content: homepage, product features, pricing, and security information. Its sign-in and application calls to action are ordinary links to Laravel/Filament `/manage`; it never interprets Laravel authentication state.

Business rules are never implemented only in Filament callbacks or Blade templates. Both surfaces call Laravel actions guarded by policies and transactions.

PHPStan with Larastan runs at level 5 in the local and CI lint gates. The checked-in baseline records legacy findings so every new or changed application path is held to the gate without hiding regressions.

## Source references

The version choices and architecture follow current official documentation:

- Laravel 13 release and support policy: https://laravel.com/docs/13.x/releases
- Laravel Sanctum API token authentication: https://laravel.com/docs/13.x/sanctum
- Laravel queues and scheduler: https://laravel.com/docs/13.x/queues and https://laravel.com/docs/13.x/scheduling
- Filament 5 resources, custom pages, tenancy, authentication, and security: https://filamentphp.com/docs/5.x/resources/overview, https://filamentphp.com/docs/5.x/navigation/custom-pages, https://filamentphp.com/docs/5.x/users/tenancy, https://filamentphp.com/docs/5.x/users/overview, https://filamentphp.com/docs/5.x/advanced/security
- Next.js 16 App Router for public content: https://nextjs.org/docs/app
