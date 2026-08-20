# P3-07F contract-foundation evidence

This is contract and persistence evidence only. It does not claim that public booking works.

## Git lineage

- Initial post-Agent-04 base: `dd48b504d807f4cffd1ca7027bfe09b02d8c0f11`
- Rebased after P3-06B tender merge onto: `028cde9d1c0235f654385e651121bdac7fa6035f`
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
- Deterministic full-envelope screen/state/error fixtures plus mock router for Agent 08, with public-action parity and distinct status errors.
- Threat model and same-origin Laravel ADR.

## Gate receipts

- Direct contract: 12 paths, 15 full state envelopes, 13 errors, 18 fixtures; exact transition authority and public-action parity checked.
- Aggregate OpenAPI: 118 paths, 152 operations, 112 resolved references.
- SQLite direct booking: 17 passed / 160 assertions, plus 3 intentional PostgreSQL skips.
- PostgreSQL direct booking: 20 passed / 177 assertions, including different-command, same-retry and revoke-versus-rotate row-lock races.
- Full SQLite normal Laravel command: 375 passed / 3,007 assertions, plus 25 intentional PostgreSQL skips.
- Full PostgreSQL: 400 tests / 3,156 assertions, plus one platform-specific migration skip.
- Commercial migration compatibility: 2 passed / 24 assertions. Payment/tender/reservation PostgreSQL compatibility: 84 passed / 836 assertions. The `060002` clean round-trip and guarded rollback run on both engines inside the direct-booking suites.
- Tender/reservation nested-JSON card-guard regression: 18 passed / 192 assertions on SQLite and PostgreSQL.
- Pint: 819 files. PHPStan: zero errors. API and Next production builds: pass.
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

The branch was refreshed against `origin/main` at `028cde9d1c0235f654385e651121bdac7fa6035f` immediately before the final gates. Provider and booking-browser UAT remain downstream Agent 07/08/09 evidence, not foundation evidence. Commit, draft PR and CI URLs are added in the PR handoff; this file does not claim a working booking journey.
