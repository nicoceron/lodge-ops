# P3-07F contract-foundation evidence

This is contract and persistence evidence only. It does not claim that public booking works.

## Git lineage

- Initial post-Agent-04 base: `dd48b504d807f4cffd1ca7027bfe09b02d8c0f11`
- Rebased after P3-06B tender merge onto: `028cde9d1c0235f654385e651121bdac7fa6035f`
- Merged current integration-kernel `main` non-destructively at: `7ddd9ca08144ead0fd53c0bbc51185c123d0837a`
- Branch: `codex/p3-07f-direct-booking-contract`
- Migration namespace: `2026_08_20_06xxxx` (`060001` foundation, additive `060002` review hardening)

## Evidence scope

- 12 frozen public routes with fail-closed handlers pending Agent 07.
- 15 states, transition authority, optimistic version and durable retry identity.
- 13 standardized public errors.
- Versioned localized public property/category/program/policy/media sources.
- Property/currency/localized payment capability boundary and dedicated locked held-reservation payment-request issuance.
- Independently expiring hash-only session/recovery credentials, immutable separate consent facts, attribution/IP minimization and dedicated PII scrub event/Guest cleanup deferral.
- Property-inclusive composite associations with same-tenant cross-property negative coverage.
- Deterministic full-envelope screen/state/error fixtures plus mock router for Agent 08, with public-action parity and one distinct schema-valid fixture for every error.
- Threat model and same-origin Laravel ADR.

## Gate receipts

- Direct contract: 12 paths, 15 full state envelopes, 13 errors, 25 fixtures; exact transition authority, public-action parity and complete error status/retryability/envelope parity checked.
- Aggregate OpenAPI after the integration-kernel merge: 134 paths, 170 operations, 118 resolved references.
- SQLite direct booking: 18 passed / 168 assertions, plus 5 intentional PostgreSQL skips.
- PostgreSQL direct booking: 23 passed / 197 assertions, including different-command, same-retry, revoke-versus-rotate, expired-versus-recovery and expired-versus-late-payment-review row-lock races.
- Full SQLite normal Laravel command: 409 passed / 3,422 assertions, plus 34 intentional PostgreSQL skips.
- Full PostgreSQL: 443 tests / 3,635 assertions, plus one platform-specific migration skip.
- Commercial, integration-kernel, payment, tender and reservation compatibility are included in both complete engine suites. The `060002` clean round-trip and guarded rollback run on both engines inside the direct-booking suites.
- The tender/reference guard regressions preserve nested-JSON inspection, exact digest/generated-storage admission, underscore-prefixed UUID removal and PAN/SAD rejection on SQLite and PostgreSQL.
- Pint: 896 files. PHPStan: zero errors. API and Next production builds: pass.
- Next lint/typecheck: pass. Playwright public marketing checks: 4 passed.
- Composer and both npm dependency audits: zero known vulnerabilities. Real mock HTTP checks prove exact published `Cache-Control`/`Content-Language` and private `no-store` behavior.
- `git diff --check`: pass.

Representative commands:

```bash
ruby scripts/verify-direct-booking-contract.rb
make contract
docker compose run --rm --no-deps -e APP_ENV=testing -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: api php artisan test --compact
make test-api-postgres
make lint
git diff --check
```

The branch was refreshed through integration-kernel `origin/main` at `7ddd9ca08144ead0fd53c0bbc51185c123d0837a` immediately before the final gates. Provider and booking-browser UAT remain downstream Agent 07/08/09 evidence, not foundation evidence. Commit, draft PR and CI URLs are added in the PR handoff; this file does not claim a working booking journey.
