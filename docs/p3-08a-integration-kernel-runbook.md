# P3-08A integration execution kernel runbook

This runbook operates Inn's provider-neutral integration kernel. It does **not** certify an OTA, accounting product, email provider, fiscal provider, or production webhook receiver. A connection becomes operational only when a named provider/product adapter registers the exact capability port and completes its own provider round trip.

## Boundaries

- Provider credentials and signing secrets remain in an approved secret manager. Inn stores references such as `env:NAME`, `vault://path`, or cloud-secret-manager URIs, never values.
- `communication_provider_connections` remains separate. This kernel does not repoint or copy communications credentials.
- Adapters may call capability-specific application/domain commands with `IntegrationServiceIdentity`; they must not write reservation, allocation, guest, folio, payment, or other domain tables directly.
- Outbound ports select committed immutable outbox/domain facts. Provider acknowledgements never rewrite the source facts.

## Configure and enable

1. Create the secret in the selected secret manager and grant the API/worker runtime read access.
2. In **Templates & Integrations → Integration connections**, create a connection with property scope, provider, product, external account, environment, explicit capabilities, non-secret configuration, and the secret reference.
3. Use **Test** for one registered capability. A missing named port is a failed configuration, not generic success.
4. Use **Enable** with an actor reason. Enabled capability rows are required independently of the connection flag.
5. For inbound webhooks, rotate the endpoint key. Copy the raw value immediately to the provider and/or secret manager; only its SHA-256 hash is retained. A repeated rotation idempotency identity never returns the old raw value.

Legacy Mercado Pago rows retain their connection ID and provider/account/environment/property identity. Their previous endpoint value is converted to a hash for inbound compatibility, removed from configuration, and an open `legacy_endpoint_key_rotation` reconciliation instructs an operator to rotate/store a new raw endpoint key before initiating new checkouts.

## Run and cursor policy

- Start runs from the connection action or `POST /api/v1/integrations/{connection}/runs` with a stable `Idempotency-Key`.
- Each capability uses its own port: `reservations.import`, `accounting.journal_export`, or `webhook.outbound`.
- A fetched page is persisted before items are queued. A recovery heartbeat redispatches persisted pending items and never fetches beyond an unfinished page.
- The cursor commits only after every page item is `succeeded` or `dead_letter`. Poison items therefore remain visible without blocking unrelated items forever.
- Replaying an item from an already committed page cannot advance or rewind its cursor. Same-key/same-command run retries return the same run; same key with changed facts fails.

## Webhooks

- Send Standard Webhooks headers `webhook-id`, `webhook-timestamp`, and `webhook-signature` to `/api/v1/integration-webhooks/{endpointKey}`.
- Verification uses HMAC-SHA256 over the exact bytes `webhook-id.webhook-timestamp.raw-body`, with the five-minute default tolerance.
- Inn stores the external identity, raw checksum, bounded safe snapshot, and timestamps—not the raw body or signature headers—then returns `202` and queues processing.
- Duplicate external identities/checksums resolve to the original immutable receipt. Unknown external accounts and cross-connection identity collisions become reconciliation work instead of being guessed into a domain command. Capability ports remain responsible for authoritative version ordering and domain-command idempotency when polling and webhook delivery race.

## Retry, circuit, and dead letters

- Transport defaults are 5-second connect and 20-second request timeouts.
- GET/HEAD and mutations with stable remote idempotency may retry connection errors and transient 5xx up to three attempts.
- `429` honors delta-seconds or HTTP-date `Retry-After` (bounded to one hour) and records `throttled_until`.
- Five recorded terminal failures open the circuit for five minutes. An ambiguous mutation timeout requires an authoritative recovery callback; never infer failure from a timeout.
- Queue jobs try four times with 10/60/300-second backoff. Exhausted or poison facts become dead letters with normalized errors.
- Replay requires a terminal original, confirmation, actor and reason. Successful replay resolves the dead-letter record; it is never deleted.

## Health and reconciliation

Connection health exposes last success/error/event, lag, backlog, success/error counts, average item latency, open dead letters, throttle/circuit state, and the scheduler heartbeat. `integrations:heartbeat` runs every minute, publishes safe backlog gauges, and recovers stale claimed run items and webhook events before redispatching recoverable persisted pages.

Use **Reconcile** to project open dead letters into owned reconciliation work. Resolve reconciliation only after checking the provider's authoritative record and document the resolution. Disable first for maintenance; revoke only when credentials/endpoints must be irreversibly invalidated.

## Incident checks

```bash
php artisan integrations:heartbeat
php artisan queue:work --queue=integrations --tries=4 --timeout=180
php artisan route:list --path=integration
```

Inspect safe checksums and correlation IDs rather than asking for raw payloads or credentials. Search `integration_operations` for actor/reason history. If a raw endpoint key was lost, rotate it; it cannot be recovered from Inn.

## Verification

```bash
php artisan test --compact tests/Feature/Integrations
php artisan test --compact tests/Feature/PostgresIntegrationConcurrencyTest.php
make contract
make test-api-postgres
```

Use `Http::preventStrayRequests()` in every adapter contract suite. Test fakes prove kernel semantics only and must never be presented as a real provider integration.
