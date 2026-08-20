# P3-08A integration kernel evidence

Date: 2026-08-20
Original base: `5092d682caee365f069ad0734c60cebd96f512bc`
Synchronized main: `028cde9d1c0235f654385e651121bdac7fa6035f`
Branch: `codex/p3-08a-integration-kernel`

## Delivered scope

- Canonical tenant/property/provider/product/account/environment connection identity with null-safe global uniqueness.
- Existing Mercado Pago ID/identity backfill, hashed rotating endpoint keys, overlap/revocation, secret references, and a lossless encrypted legacy-key transition with explicit reconciliation.
- Capability rows and distinct reservation-import, accounting-export, inbound-webhook and outbound-webhook ports.
- Versioned mappings, crash-safe paged runs/items/cursors, immutable verified events, dead letters, replay and reconciliation.
- Stable local/remote idempotency, service identity, safe errors/checksums, timeout/429/5xx/circuit policy, heartbeat and safe health gauges.
- Property/role policies, API/OpenAPI, and Filament resources/actions for connection health, mappings, runs, events, dead letters and reconciliation.

No provider-specific mapping or credential was added. No real OTA/accounting/communications integration is claimed.

## Executed evidence

- Focused integration plus commercial/payment/tender regression after remediation: 83 tests, 82 passed, 962 assertions, one existing provider/environment skip.
- Explicit PostgreSQL claim/item/event, cursor restart, disable-during-run, secret-rotation-during-request, and replay races: 7 tests / 52 assertions.
- Full SQLite suite: 414 tests, 386 passed, 3,217 assertions, 28 production-engine/provider skips classified by the existing suite.
- Full PostgreSQL suite: 414 tests / 3,346 assertions with one existing provider/environment skip.
- PostgreSQL kernel/commercial migration upgrade/rollback/up compatibility: 5 tests / 50 assertions.
- `make lint`, API generation, API and web production builds, and `make contract` passed; the final contract contains 121 paths, 156 operations, and 112 resolved references.
- Rebuilt Compose health and doctor passed. Playwright web passed 4/4; the client suite passed 8 journeys with one expected provider-mode skip.
- Compose deterministic Checkout Pro UAT completed checkout, exact signed webhook receipt, worker processing, and an approved payment. Visible browser UAT confirmed that secret references and fixture details render only as configured markers and that connection, run, and mapping surfaces load under the property-scoped administrator session. See `remediation-uat.md`.
- Composer and npm dependency audits, the diff/credential scan, and `git diff --check` passed before publication.

## References applied

- Laravel 13 HTTP client, queues, events, cache locks, rate limiting and scheduling documentation.
- Standard Webhooks specification exact raw-body signing construction and timestamp tolerance.
- RFC 5545 boundary: calendar transport remains capability/provider-specific; this kernel does not invent a calendar sync.
- OpenAPI 3.1 contract specification.
