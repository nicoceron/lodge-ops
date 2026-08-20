# Agent 10 — P3-08A integration execution kernel

## Copy/paste assignment

> Build the provider-neutral integration execution kernel from synchronized `main` after P3-06A. Read this file, the coordinator README, current IntegrationConnection code/UI, domain commands, queue/event/idempotency patterns, Laravel HTTP client docs, Standard Webhooks and relevant protocol specs. Deliver durable connections, secret references, mappings, runs/items/cursors/events, verified webhook ingestion, retries/dead letters/replay, health/reconciliation UI and tests. Do not implement a fake “generic sync,” mutate domain tables directly, or claim an OTA/accounting integration without a named provider and real round trip.

## Branch and ownership

- Branch: `codex/p3-08a-integration-kernel` after Agent 00.
- Own integration connection/runtime schemas, capability contracts, transport policy, events/runs/items/cursors/mappings/dead letters, system identity, API/Filament/OpenAPI/tests/runbook.
- Do not own provider-specific field mapping or credentials for Agent 11, payment gateway internals, or final production process topology.
- Read `IntegrationConnection`, `IntegrationConnectionService`, its resource/policy, ExtendedOperations integration endpoints, all Mercado Pago consumers, Agent 03's separate communication connection model, outbox/idempotency/jobs, tenant middleware, domain commands and audit trail.

## Persistence and identity

- Extend connections with tenant/property scope, provider/product, external account ID, environment, capabilities, configuration version, secret references, **hashed** webhook endpoint key/version, enabled/revoked state, last success/error, health and lag. Support endpoint-key rotation overlap and revocation; raw keys are returned only when issued.
- Unique identity includes tenant + provider + product + external account + environment + property scope; use canonical global scope or PostgreSQL `NULLS NOT DISTINCT`/expression indexing so nullable property scope cannot create duplicate global connections.
- Inventory and backfill existing connection rows and Mercado Pago references without changing provider/account/environment identity. Preserve IDs where consumers depend on them, provide dual-compatible rollout/rollback, and reconcile exception rows instead of guessing.
- Agent 03's `communication_provider_connections` remains separate in this phase. Either add an explicit compatible mapping/migration plan with full P3-04 regression or leave it as a supported capability-specific consumer; never silently repoint email credentials.
- Add versioned mappings with direction, local/external entity/key, transform version, validity and conflict state.
- Add sync runs/items with trigger/direction/capability, cursor/checkpoint, status, counts, attempt, lease/claim, started/finished, normalized safe error and correlation.
- Add immutable integration events with external ID/type/version, raw checksum, safe normalized snapshot, occurred/received/processed timestamps, disposition and uniqueness.
- Add dead-letter/reconciliation records with reason, owner, retry/replay history and resolution. Never delete poison facts to make a run green.
- Secrets stay in a secret manager/reference resolver. Rotating or revoking a secret is audited; plaintext never appears in DB/UI/logs/jobs.

## Capability contracts and execution

- Use capability-specific ports (for example reservations import, accounting journal export, outbound webhook), not one universal connector interface.
- Commands: test connection, enable/disable/revoke, rotate secret reference, start/resume sync, ingest verified event, process item, replay item/event, reconcile and health-check.
- Imported facts invoke existing Inn application/domain commands with a service identity, property scope and stable idempotency key. Connectors never write reservations, allocations, folios, payments or guests directly.
- Outbound facts come from committed immutable domain/outbox events; a remote acknowledgment does not rewrite source facts.
- HTTP policy: explicit connect/request timeout, safe selective retry, `Retry-After`, provider throttle, circuit/open-degraded state, stable remote idempotency and recovery after timeout.
- Webhook: opaque endpoint key, exact raw-body signature/time validation, quick immutable receipt then queued processing; duplicate/reordered events safe.
- Cursor/page progress commits only with item outcomes. Crash/restart does not skip or duplicate a page.

## Operations and security UI

- Filament resources: connections/capabilities, mappings, runs/items, events, health/lag, dead letters, reconciliation and authorized replay.
- Actions require explicit policy and property scope; destructive disable/revoke/rotate/replay confirms impact and records actor/reason.
- Show safe request/response checksums and normalized errors, never raw auth headers, tokens, webhook bodies or unnecessary guest content.
- Emit metrics for success/error/429/latency/lag/backlog/dead letters/last event and scheduler heartbeat for Agent 13.

## Tests and completion

- Contract fakes for success, invalid auth, 429/retry-after, 5xx, timeout before/after remote success, malformed/partial page and mapping drift.
- Raw signature invalid/missing/stale, duplicate/reordered event, webhook-versus-poll race and unknown account.
- PostgreSQL races: two run claimers, same item/event, cursor restart, disable during run, secret rotation during request, replay while original active.
- Cross-tenant/property/role/IDOR and system-identity capability limits.
- Poison item does not block unrelated items; dead-letter replay is idempotent; cursor advances only according to documented policy.
- Use `Http::preventStrayRequests()` in tests; no test accidentally calls a real provider.
- Browser UAT: configure by secret reference → test → run with mixed outcomes → inspect/replay dead letter → reconcile → disable → prove blocked.
- Run universal gates, integration-focused PostgreSQL/queue tests, fresh migration/backfill/rollback, and the complete P3-06 payment/provider regression. Completion is the kernel only, not a provider integration.

## Primary references

- [Laravel HTTP client](https://laravel.com/docs/13.x/http-client), [queues](https://laravel.com/docs/13.x/queues), [events](https://laravel.com/docs/13.x/events), and [cache locks](https://laravel.com/docs/13.x/cache)
- [Standard Webhooks specification](https://github.com/standard-webhooks/standard-webhooks/blob/main/spec/standard-webhooks.md)
- [RFC 5545 iCalendar](https://datatracker.ietf.org/doc/rfc5545/)
- [OpenAPI specification](https://spec.openapis.org/oas/)
