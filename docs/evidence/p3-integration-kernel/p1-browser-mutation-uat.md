# P3-08A ordinary-browser mutation receipt

Date: 2026-08-20

Environment: local Compose PostgreSQL/Redis stack with the assignment-only `contract_fake` reservation port enabled under Laravel's testing environment. This is deterministic contract evidence, not a real provider or production-integration claim.

Actor: property-scoped administrator in the ordinary Chrome Filament session at `http://localhost:8000/manage/workspace/demo-lodge`. Every mutation below was performed by opening the visible Filament action/form, entering the rendered fields, and clicking its action button. No mutation API or database command was used.

## Redacted facts

- Tenant workspace: `demo-lodge`
- Property: Estancia Viento Sur (`01a020d9-c557-71e5-8fae-452eab8dd8d7`)
- Connection: Browser mutation UAT 20260820 (`01a02144-6fc8-734f-ac3a-f6d86fbbd05f`)
- Canonical identity: `contract_fake` / `mixed_reservations` / `browser-uat-20260820` / `test`
- Capability: `reservations.import`, inbound
- Secret configuration: an approved secret-reference URI was entered; the URI and its resolved value are intentionally omitted. Filament rendered only `[configured]`.
- Final observed run: `01a0214b-6334-70cf-ba09-8b68686d9e3d`
- Correlation ID: `17239d0a-8c68-4b89-9f6b-200aaa200f8a`
- Stable run idempotency key: `filament:4eb314d1-2213-4ce9-ab9b-5414a412c52a`
- Dead letter: `01a0214b-6358-73c8-9d53-f913c701029b`

## Visible mutation journey

1. **Configure:** Created the property-scoped connection through the Integration Connections create form using canonical provider/product/account/environment fields, `reservations.import`, and the secret reference. The resulting table showed the canonical identity and no secret value.
2. **Test:** Ran the visible Test action for `reservations.import`; Filament reported `Connection test passed` using safe contract-fixture text.
3. **Enable:** Ran Enable with an operator reason. The connection changed to `Connected`, and Start run became available.
4. **Mixed run:** Ran Start run for `reservations.import`. The visible run page showed one page and two items: `uat-good` succeeded on attempt 1, while `uat-poison` entered `dead_letter` with safe error `Test-only unmapped reservation fact.`
5. **Inspect:** The non-lazy capability table showed enabled state, configuration version 1, last-sync/error timestamps, and the safe error. The non-lazy item table showed statuses, attempts, truncated checksums, idempotency keys, and dead-letter linkage. It exposed neither configuration secrets nor raw payloads.
6. **Replay:** Opened the visible Replay action for dead letter `01a0214b-6358-73c8-9d53-f913c701029b`, entered a reason, and confirmed. The dead-letter table changed from `open / 0` to `resolved / 1`; the poison item succeeded on attempt 2.
7. **Reconcile:** Ran the visible Reconcile action after replay. Filament reported `0 open reconciliation item(s)`.
8. **Disable and block:** Ran Disable with an operator reason. The row changed to `Disabled`; Start run disappeared and only Enable remained. This is the visible policy fence. Service/API attempts against a disabled connection are covered by the focused and PostgreSQL race suites.

## Persisted postconditions (read-only observation)

- Run status: `completed`; item count 2; success count 2; error count 0; dead-letter count 0; page 1.
- `uat-good`: succeeded, attempt 1, payload checksum prefix `72e1d84b3a19`, request checksum prefix `107c69f03385`, response checksum prefix `72e1d84b3a19`.
- `uat-poison`: succeeded after replay, attempt 2, payload checksum prefix `d3fc54b87832`, request checksum prefix `140f58305ae9`, response checksum prefix `d3fc54b87832`.
- Dead letter: resolved, replay count 1, resolution `Replay succeeded.`
- Open reconciliation count: 0.
- Connection: disabled, not revoked, with a recorded last-sync timestamp. No raw credential, provider payload, authorization header, PAN, or secret-reference URI appeared in the rendered pages or this receipt.

Blocked-run resume remains covered by the automated browser-policy and PostgreSQL concurrency suites. The synchronous contract run completed before a browser disable could create a genuine blocked run, so this receipt does not claim a browser-observed resume.
