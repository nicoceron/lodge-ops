# P3-07A direct-booking domain/API evidence

Branch: `codex/p3-07a-direct-booking-api`

Base commit: `7a51d3613bd2a0ba9576824f0a7e9ec7a96011e6`

## Software receipt

The state-changing anonymous API tests execute both payment branches against the real application services and database schema:

- Manual transfer: public property/search, exact begin replay, authoritative quote, atomic hold, deposit creation, private scanned evidence, Finance approval, reservation confirmation and confirmation projection. The final ledger contains one payment and three folio lines for the two-night fixture plus its payment, with no duplicate order/request effects.
- Hosted checkout: public begin/quote/hold/checkout, non-authoritative return/status poll, authoritative rejected lookup, superseded request retry, authoritative approved lookup, exactly-once payment/deposit/folio application and reservation confirmation. Duplicate event processing retains one payment and one direct order.
- Encrypted public command responses replay the byte-identical successful response and are removed after expiry by protected maintenance. The test proves the plaintext response is not stored in the encrypted column.
- Authorized readiness diagnostics return the exact blocker list while anonymous endpoints remain generic. Frozen contract verification retains all 12 direct-booking paths, 15 states, 13 errors and 25 fixtures.

## Gate record

- SQLite direct-booking: 26 tests, 233 assertions, with five expected PostgreSQL-only skips.
- Focused payment, provider, commercial and tender regressions: 72 tests, 873 assertions.
- PostgreSQL direct-booking: 26 tests, 262 assertions across the API and concurrency/schema groups, including state/version, retry, token and retention races.
- Universal SQLite: 446 tests, 3,485 assertions, 34 expected production-engine skips. Universal PostgreSQL: 446 tests, 3,698 assertions, one expected Docker host-path skip.
- OpenAPI: 135 paths, 171 operations and 119 resolved references; frozen direct contract: 12 paths, 15 states, 13 errors and 25 fixtures.
- Full Pint (904 files) and PHPStan: clean. API/web production builds, ESLint and TypeScript passed. Composer and both npm audits reported no advisories/vulnerabilities; the source-diff secret scan reported no leaks.
- An isolated `inn-agent07` Compose stack built and started PostgreSQL, Redis, Mailpit, migrations, API, worker, scheduler and web. API `/up`, Filament `/manage/login` and the web root returned HTTP 200; the scheduler completed the named direct-booking maintenance command, and an unknown public property returned the frozen generic HTTP 503 envelope with `Retry-After` and a correlation ID.

The draft PR records the exact commands and the remaining runtime/provider evidence boundary.

## Known contract and provider gates

The frozen v1 accepts `optional_service_keys` but defines no public service-key projection or discovery source. Nonempty selections therefore fail safely instead of accepting internal catalog UUIDs or creating an undocumented v1 mapping. Included services continue to use the authoritative commercial engine. A later public optional-service catalog requires a versioned contract.

No real direct-booking Mercado Pago checkout, provider-originated public-HTTPS delivery, production merchant account, production queue, email delivery or production document receipt was observed for P3-07A. The provider branch uses the deterministic gateway fake and real provider-event/payment/reconciliation services. P3-06A's accepted Colombia/MCO + COP journey is shared-substrate evidence only; its Argentina/MLA + ARS regional certification and production-origin delivery boundaries remain unchanged.
