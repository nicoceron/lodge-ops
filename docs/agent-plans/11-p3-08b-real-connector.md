# Agent 11 — P3-08B one real integration connector

## Copy/paste assignment

> Implement exactly one real integration outcome after Agent 10’s kernel is merged. Read this file, the coordinator README, the selected provider’s current official API/security/certification docs, the kernel contracts/runbook, and the relevant Inn domain commands. Name the provider/outcome in the branch and plan. Use real sandbox or independent endpoint evidence, including authentication, mapping, retry, webhook/poll, replay and reconciliation. Do not claim OTA, accounting, WhatsApp or e-signature from a scaffold/export alone. If no commercial provider is selected by the start date, implement the default Standard-Webhooks-compatible outbound connector to an independently hosted receiver and label it only as signed outbound webhooks.

## Branch and selection gate

- The coordinator makes the provider/outcome decision **before** branch creation.
- Branch: `codex/p3-08b-integration-<provider>`; for the default use `codex/p3-08b-integration-standard-webhooks`.
- Additional prerequisites by outcome:
  - accounting: Agents 00, 01, 04 and 10;
  - OTA/channel manager: Agents 04, 05 and 10;
  - SMS/WhatsApp: Agents 03 and 10;
  - e-signature: P3-03, Agents 10 and 12;
  - Standard Webhooks outbound: Agent 10 only; final production evidence waits for Agent 13 runtime.
- Before coding, create `docs/evidence/p3-08b/decision.md` with named outcome/provider, official docs URLs/date read, account owner/environment, auth method, scopes, webhook/signature model, rate limits, certification, data residency, cost/contract assumptions and supported object/state matrix.
- Valid options require a real account/API and business journey:
  - OTA/channel manager: inventory/rates/restrictions/reservations/cancel/amend acknowledgment;
  - accounting: balanced journal/customer/payment/refund/fee/tax mapping and import acknowledgment;
  - SMS/WhatsApp: consent/template lifecycle/delivery events for named communication journeys;
  - e-signature: named legal document/signers/status/evidence package;
  - default signed outbound webhook: versioned Inn events to an independent receiver with acknowledgment/replay.
- iCal alone is not OTA sync. A ZIP/CSV alone is not external accounting integration. An email with a PDF is not e-signature.

## Implementation contract

- Add only the provider adapter, DTOs/mappings, provider-specific configuration validation, fixtures and UI needed by the selected outcome. Use Agent 10’s connection, run/item/event/dead-letter/replay primitives.
- Resolve secrets by reference and least scopes. Verify account/environment on connection test; prevent accidental production calls from test/dev.
- Define authoritative IDs, versions, state transitions, date/time zone, currency/minor-unit and deletion/cancellation semantics.
- Stable idempotency for every outbound mutation. After timeout/5xx, fetch/reconcile before retry. Respect `Retry-After` and official quotas.
- Verify webhooks from raw body, timestamp and local endpoint/account mapping. Fetch the authoritative provider resource when the protocol supports it.
- Imported changes call Inn commands; exported changes originate from committed outbox/domain facts. Preserve immutable source and provider acknowledgments.
- Mappings are explicit and versioned. Unknown/missing/drifted values enter reconciliation, never silent defaults.

## Default connector when no provider is selected

- Implement Standard-Webhooks-compatible subscriptions scoped by tenant/property/event types, endpoint URL allowlist, secret reference/version, enabled state and delivery policy.
- Versioned event envelope: unique event ID/type/version, occurrence time, property/tenant-safe identifiers, schema version and minimal allowlisted payload.
- Sign exact bytes with timestamp and secret version. Protect against SSRF: HTTPS, resolved-IP/private-range checks, redirect policy, DNS rebinding mitigation and egress allowlist.
- Durable deliveries/attempts with stable event identity, exponential backoff/jitter, maximum age, dead letter, authorized replay and endpoint health.
- Stand up a reproducible independent receiver as a separately deployed minimal service/container with its own repository/image digest, public HTTPS URL, request log/checksum and no shared Inn database/process. Verify signature, intentionally fail/recover, replay and reconcile counts/checksums.
- Honest claim: “signed outbound webhook integration.” Do not imply the receiver is an OTA/accounting product.

## Required tests and live proof

- Official fixtures plus 2xx/4xx/401/403/409/429/5xx, malformed payload, timeout after success, paginated partial run and stale cursor.
- Same/different idempotency payload, duplicate/reordered webhook, poll/webhook race, secret rotation overlap, disabled/revoked connection, provider account mismatch.
- Mapping drift/unknown value/rounding/time-zone/cancellation/reopen; cross-property/role/IDOR and PII/redaction.
- Browser UAT configures, tests, runs the exact selected capability/direction, observes an induced failure, replays, reconciles totals/status and disables safely. The outbound-only default proves delivery/acknowledgment/failure/replay/reconciliation, not an import.
- Real sandbox/receiver evidence includes redacted external IDs, timestamps, checksums, provider/receiver view, Inn view and no secrets.
- Run universal gates, kernel regression, ordinary queue worker and dependency/credential scans.
- Update only this connector’s evidence. Coordinator records every other advertised integration as not implemented or explicitly deferred.

## References

- Read the selected provider’s official docs on implementation day and record exact pages in the decision file.
- [Standard Webhooks specification](https://github.com/standard-webhooks/standard-webhooks/blob/main/spec/standard-webhooks.md)
- [Laravel HTTP client](https://laravel.com/docs/13.x/http-client), [queues](https://laravel.com/docs/13.x/queues), and [encryption](https://laravel.com/docs/13.x/encryption)
- [OWASP SSRF Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html)
