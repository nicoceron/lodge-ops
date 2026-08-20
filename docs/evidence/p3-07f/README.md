# P3-07F contract-foundation evidence

This is contract and persistence evidence only. It does not claim that public booking works.

## Git lineage

- Initial post-Agent-04 base: `dd48b504d807f4cffd1ca7027bfe09b02d8c0f11`
- Rebased after P3-06B tender merge onto: `028cde9d1c0235f654385e651121bdac7fa6035f`
- Branch: `codex/p3-07f-direct-booking-contract`
- Migration namespace: `2026_08_20_06xxxx` (`060001` only)

## Evidence scope

- 12 frozen public routes with fail-closed handlers pending Agent 07.
- 15 states, transition authority, optimistic version and durable retry identity.
- 13 standardized public errors.
- Versioned localized public property/category/program/policy/media sources.
- Property/currency payment capability boundary.
- Hash-only opaque order tokens, immutable separate consent facts, attribution/IP minimization and PII scrub schedule.
- Deterministic screen/state/error fixtures plus mock router for Agent 08.
- Threat model and same-origin Laravel ADR.

## Gate receipts

- Direct contract: 12 paths, 15 states, 13 errors, 13 fixtures; exact transition authority checked.
- Aggregate OpenAPI: 118 paths, 152 operations, 112 resolved references.
- SQLite direct booking: 11 passed / 81 assertions, plus 2 intentional PostgreSQL skips.
- PostgreSQL direct booking: 13 passed / 92 assertions, including different-command and same-retry row-lock races.
- Full SQLite normal Laravel command: 369 passed / 2,926 assertions, plus 24 intentional PostgreSQL skips.
- Full PostgreSQL: 393 passed / 3,069 assertions, plus one platform-specific migration skip.
- Payment/tender/reservation PostgreSQL compatibility: 83 passed / 820 assertions.
- Tender/guest UUID-path false-positive regression: 21 passed / 262 assertions.
- Pint: 815 files. PHPStan: zero errors. API and Next production builds: pass.
- Next lint/typecheck: pass. Playwright public marketing checks: 4 passed.
- `git diff --check`: pass.

Representative commands:

```bash
ruby scripts/verify-direct-booking-contract.rb
make contract
docker compose run --rm --no-deps -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: api php artisan test --compact tests/Feature/DirectBooking
make test-api-postgres
make lint
git diff --check
```

The branch was refreshed against `origin/main` at `028cde9d1c0235f654385e651121bdac7fa6035f` immediately before the final gates. Provider and booking-browser UAT remain downstream Agent 07/08/09 evidence, not foundation evidence. Commit, draft PR and CI URLs are added in the PR handoff; this file does not claim a working booking journey.
