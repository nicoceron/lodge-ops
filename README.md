# LodgeOps

LodgeOps is a multi-tenant operating system for lodges and outfitters. It coordinates sales, reservations, accommodations, guides, shared resources, guest preparation, kitchen operations, payments, communications, tasks, and reporting in one system.

The product intentionally improves on the legacy LodgeRunner workflow instead of reproducing its duplicate room/group/guide booking variants:

- one reservation composer;
- one constraint-aware allocation engine for rooms, guides, horses, boats, vehicles, and equipment;
- one unified calendar with role-specific lenses;
- separate sales, reservation, payment, and fulfillment states;
- secure tenant-scoped API and opaque public links;
- Filament for back-office configuration and Next.js for interaction-heavy workspaces.

## Stack

- Laravel 13 / PHP 8.5: domain rules, API, authorization, queues, automation
- Filament 5: tenant administration and operational configuration
- Next.js 16 / React 19 / Tailwind CSS 4: staff and guest experience
- PostgreSQL 18, Redis 8, Mailpit, Docker Compose
- PHPUnit, Vitest, Testing Library, and Playwright

## Local development

Requirements: Docker with Compose. Local PHP 8.5 and Node 24 are optional for faster test runs.

```bash
make bootstrap
make up
make doctor
```

`make up` starts the complete stack in the background and waits for the API and web health checks. Use `make logs` to follow the application, worker, and scheduler logs, and `make down` to stop containers without deleting database or upload volumes.

- Staff experience: http://localhost:3000
- Staff sign-in: http://localhost:3000/login
- API and Filament: http://localhost:8000 and http://localhost:8000/admin
- Local email inbox: http://localhost:8025
- Guest preview: http://localhost:3000/guest/access/g_7JvK2pQ9xR4mN8tW3cD6hF1sB5yE0uA

The deterministic development seed creates `admin@example.com` / `password` for tenant `11111111-1111-4111-8111-111111111111` and a resettable, one-time guest preview link. Demo credentials are never pre-filled unless `NEXT_PUBLIC_DEMO_MODE=true`; normal local Compose runs use the live Laravel API and persisted PostgreSQL data.

Run the complete formatter, type, unit, production-build, and browser suite with:

```bash
make verify
```

If ports 5432 or 6379 are already occupied, Compose supports explicit alternatives:

```bash
POSTGRES_PORT=55432 REDIS_PORT=56379 make up
```

The backend suite is also exercised against PostgreSQL in CI; SQLite remains a fast local feedback path.

## What is implemented

- A responsive staff workspace for the unified calendar, reservations, CRM, operations, kitchen, finance, and configuration.
- A branded mobile-first guest reservation center with itinerary, pre-arrival data, consent, waivers, payment evidence, final folio, and post-stay survey flows.
- Stateful Sanctum authentication, verified-email enforcement, password recovery, shared TOTP/recovery-code MFA, explicit tenant selection, role-separated permissions, database-enforced tenant relationships, and a centralized audit history.
- Transaction-safe reservation confirmation, half-open allocation checks, service capacity, holds/status transitions, optimistic revisions, minor-unit money, payments, folio credits, and retry-safe commands.
- Tenant-aware Filament resources for day-to-day configuration, commercial workflows, finance, inventory, communications, documents, integrations, and exception work.
- After-commit outbox delivery with tenant-context restoration, retries, observable failures, and idempotent automation actions for tasks, communications, and deposit reminders.
- A checked-in OpenAPI contract, deterministic fixtures, Docker runtime, and CI gates spanning Laravel, Filament, Next.js, accessibility-oriented component tests, and Playwright desktop/mobile journeys.

## Repository map

- `apps/api`: Laravel application and Filament panels
- `apps/web`: Next.js application
- `contracts`: versioned HTTP API contract
- `docs`: product traceability, architecture, security, and operations
- `docker`: reproducible runtime images

The source scrape used for product research is deliberately outside this repository and is never copied into builds, fixtures, or Git history.
