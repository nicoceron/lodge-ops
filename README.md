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
```

- Staff experience: http://localhost:3000
- API and Filament: http://localhost:8000 and http://localhost:8000/admin
- Local email inbox: http://localhost:8025

Run verification with `make test` and `make lint`.

## Repository map

- `apps/api`: Laravel application and Filament panels
- `apps/web`: Next.js application
- `contracts`: versioned HTTP API contract
- `docs`: product traceability, architecture, security, and operations
- `docker`: reproducible runtime images

The source scrape used for product research is deliberately outside this repository and is never copied into builds, fixtures, or Git history.

