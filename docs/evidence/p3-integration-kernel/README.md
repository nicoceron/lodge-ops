# P3-08A integration kernel evidence

Date: 2026-08-20
Original base: `5092d682caee365f069ad0734c60cebd96f512bc`
Synchronized main: `028cde9d1c0235f654385e651121bdac7fa6035f`
Branch: `codex/p3-08a-integration-kernel`

## Delivered scope

- Canonical tenant/property/provider/product/account/environment connection identity with null-safe global uniqueness.
- Existing Mercado Pago ID/identity backfill, hashed rotating endpoint keys, overlap/revocation, secret references, and explicit legacy-key reconciliation.
- Capability rows and distinct reservation-import, accounting-export, inbound-webhook and outbound-webhook ports.
- Versioned mappings, crash-safe paged runs/items/cursors, immutable verified events, dead letters, replay and reconciliation.
- Stable local/remote idempotency, service identity, safe errors/checksums, timeout/429/5xx/circuit policy, heartbeat and safe health gauges.
- Property/role policies, API/OpenAPI, and Filament resources/actions for connection health, mappings, runs, events, dead letters and reconciliation.

No provider-specific mapping or credential was added. No real OTA/accounting/communications integration is claimed.

## Executed evidence

- Focused integration plus commercial/payment/tender regression after the final main merge: 85 tests, 84 passed, 1,008 assertions, one existing provider/environment skip.
- Explicit PostgreSQL two-claimer, same-event, and property-scope races: 3 tests / 18 assertions.
- Full SQLite suite: 399 tests, 375 passed, 2,990 assertions, 24 production-engine/provider skips classified by the existing suite.
- Full PostgreSQL suite: 399 tests / 3,085 assertions with one existing provider/environment skip.
- SQLite and PostgreSQL fresh migrations, kernel up/down/up, and the P3-06 lifecycle down/up compatibility path completed successfully.
- `make lint`, API generation, web production build, and `make contract` passed; the final contract contains 120 paths, 155 operations, and 112 resolved references.
- Compose health and doctor passed. Playwright web passed 4/4; the client suite passed 8 journeys with one expected provider-mode skip.
- Visible browser UAT created and tested a contract-fake connection, enabled it, ran one successful and one poison item, replayed the dead letter, reconciled it, and disabled the connection. This proves kernel operations only, not a real external integration.
- Composer and npm dependency audits, the diff/credential scan, and `git diff --check` passed before publication.

## References applied

- Laravel 13 HTTP client, queues, events, cache locks, rate limiting and scheduling documentation.
- Standard Webhooks specification exact raw-body signing construction and timestamp tolerance.
- RFC 5545 boundary: calendar transport remains capability/provider-specific; this kernel does not invent a calendar sync.
- OpenAPI 3.1 contract specification.
