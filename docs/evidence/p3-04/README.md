# P3-04 evidence ledger

Recorded 2026-08-20 from branch `codex/p3-04-production-communications`, baseline `5092d682caee365f069ad0734c60cebd96f512bc`.

## Deterministic merge evidence

| Gate | Observed result |
| --- | --- |
| Focused communication/provider/signature/scheduling | 18 tests passed, 99 assertions |
| SQLite API suite | 339 tests, 323 passed, 2,468 assertions, 16 skipped, zero failures |
| PostgreSQL communication/scheduling | 6 tests passed, 59 assertions |
| PostgreSQL two-process scheduler race | 1 test passed, 5 assertions; two occurrences and two outbox facts exactly once |
| PostgreSQL full suite | 339 tests passed, 2,565 assertions |
| PHPStan / Pint | zero errors / passed |
| OpenAPI | 94 paths, 129 operations, 102 references verified |
| Dependency audit | Composer no advisories; npm zero vulnerabilities |
| API Vite production build | passed |
| Isolated Compose | API/web/PostgreSQL/Redis/Mailpit/ordinary worker/scheduler healthy |
| Chromium P3-02 + P3-04 | 2 journeys passed; immediate survey occurrence plus marked test send/preview/Mailpit receipt/not-delivered truth |
| Chromium P3-03 standalone | 1 journey passed in 27.9 seconds on a fresh seeded Compose database |

Provider fixtures cover accepted, 429, 5xx, idempotency conflict, validation rejection and network timeout. Signed raw-body fixtures cover valid delivery, missing/invalid/stale/malformed signatures, duplicate/reordered events and complaint suppression. Provider acceptance is asserted not to set delivery truth.

## Evidence limits and activation gate

No `RESEND_*` or production communication credential was present in the authorized environment. Therefore no real Resend test-domain inbox, delivered event, bounce or complaint was observed, and this ledger makes no delivery claim. Local/fixture/contract evidence does not substitute for that provider evidence.

Production activation remains blocked pending the exact checklist in `docs/p3-04-production-communications-runbook.md`: approved provider/DPA, verified client domain and DNS, sender mapping, secret-manager credentials, real inbox plus authenticated delivered event, controlled bounce/complaint, credential rotation and alerts. Uploaded attachment sends additionally remain blocked until Agent 12's clean-scan gate is integrated.
