# LodgeOps

LodgeOps is a multi-tenant operating system for lodges and outfitters. It coordinates sales, reservations, accommodations, guides, shared resources, guest preparation, kitchen operations, payments, communications, tasks, and reporting in one system.

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
- n8n 2.32: external workflow orchestration and integration automation
- Python 3.13 / FastAPI / LangGraph: structured agent workflows and human-review recommendations
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
- n8n: http://localhost:5678
- LangGraph agent service: http://localhost:8080
- Guest preview: http://localhost:8000/guest/access/g_7JvK2pQ9xR4mN8tW3cD6hF1sB5yE0uA

The deterministic development seed creates `admin@example.com` / `password` for tenant `11111111-1111-4111-8111-111111111111` and a resettable, one-time guest preview link. Filament never pre-fills credentials; normal local Compose runs use persisted PostgreSQL data.

The seed also includes a bounded seven-month history of bookings, collections, unpaid balances, programs, category-only requests, assigned instances, housekeeping states, closed folios, note timelines, and a private channel calendar feed so every major workflow is demonstrable instead of appearing as an empty shell.

Run the complete formatter, contract, HTTP, Filament, domain, public build, and browser suite with:

```bash
make verify
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
- Tenant-aware Filament resources and custom Livewire pages for day-to-day operations, commercial workflows, finance, inventory, communications, documents, integrations, and exception work.
- After-commit outbox delivery with tenant-context restoration, retries, observable failures, and idempotent automation actions for tasks, communications, and deposit reminders.
- Optional n8n + LangGraph orchestration that receives LodgeOps automation events, produces structured AI recommendations, and persists them as idempotent staff-review tasks with human approval required.
- A checked-in OpenAPI contract, deterministic fixtures, Docker runtime, and CI gates spanning Laravel, Filament, tenant isolation, the complete guest workflow, and public-site browser journeys.

## AI automation

The AI automation layer is intentionally separated from authoritative domain writes:

1. Laravel records domain events through the transactional outbox.
2. Automation rules can invoke an `ai_workflow`, `agentic_workflow`, or `n8n_agent` action.
3. Laravel posts the event and tenant-scoped context to n8n.
4. n8n orchestrates the workflow and calls the Python/LangGraph service.
5. LangGraph returns a structured recommendation such as `draft_communication`, `create_task`, or `needs_review`.
6. Laravel stores the result as an operational task with `human_approval_required=true`.

See `docs/ai-automation.md` and `n8n/workflows/lodgeops-agent-orchestration.json` for the architecture and importable workflow.

## Repository map

- `apps/api`: the Laravel application, Filament panel, guest portal, and versioned API
- `apps/web`: public-only Next.js marketing website
- `apps/agent`: Python/FastAPI/LangGraph workflow service
- `n8n/workflows`: importable n8n workflow definitions
- `contracts`: versioned HTTP API contract
- `docs`: product traceability, architecture, security, automation, and operations
- `docker`: reproducible runtime images

The source scrape used for product research is deliberately outside this repository and is never copied into builds, fixtures, or Git history.
