# Inn ultimate completion execution pack

Date frozen: 2026-08-19

Repository: `/Users/ceron/Developer/Projects/lodge-ops`

Current payment branch at audit: `codex/p3-06-payment-gateway-mercado-pago` at `0de24b5`

Merged baseline at audit: `main` / `origin/main` at `e459935`

This directory is the copy/paste handoff pack for completing Inn. Give an agent this file **and exactly one numbered assignment file**. The assignment is not complete when code exists; it is complete only when its real state-changing acceptance journey and release gates pass.

Before launching Agents 01–15 or conditional Agent 04B, this pack must exist in their base commit. Commit it with Agent 00/PR #8 or merge it through a documentation-only PR; an untracked directory in the coordinator checkout is not visible in a new worktree.

The shortest safe prompt to launch any implementation agent is:

> Read `/Users/ceron/Developer/Projects/lodge-ops/docs/agent-plans/README.md` and `/Users/ceron/Developer/Projects/lodge-ops/docs/agent-plans/<exact-filename-from-the-index>` completely, then execute that assignment end to end in the required isolated worktree/branch. Read every local and primary reference it names before changing code. Continue through implementation, adversarial tests, real browser/provider/runtime evidence, documentation, commit, push and PR; stop only at an explicit external decision or credential/hardware gate, and report that gate without claiming completion.

This pack supersedes the old **branch order, slice dependencies, and next-action text** in `docs/client-ready-phase-3-plan.md`. It does not supersede the durable Rincón Grande requirements, completed-slice evidence, financial invariants, or explicit legal/provider caveats. The coordinator reconciles the protected status documents immediately after every merge; Agent 15 performs the final cross-document audit.

## Coordinator prompt

> You are the Inn release coordinator. Read `docs/agent-plans/README.md`, `docs/rincon-grande-requirements.md`, `docs/client-ready-phase-3-plan.md`, `docs/client-uat-ledger.md`, and `docs/reference-code-quality-benchmark.md` completely. Enforce the dependency graph and protected-file ownership in this document. Never accept a scaffold, fake provider success, controller-only test, rendered page, or healthy container as proof of a client journey. Require PostgreSQL concurrency coverage, authorization/property isolation, real browser mutation, and provider/runtime evidence where the assignment says so. Keep every branch in a separate Git worktree. Do not merge a branch whose prerequisites are not on `origin/main`, whose CI is red, or whose claimed live evidence is missing. You alone update the three master status documents. Do not implement feature code unless a conflict-resolution patch is unavoidable and separately reviewed.

## Durable scope and truth rules

- `docs/rincon-grande-requirements.md` is the durable client baseline. “Ampliación” work stays in scope unless the client defers it in writing.
- A provider-dependent capability is not production-complete without the selected provider/account, jurisdiction, operating owner, secrets workflow, and a real supported end-to-end test.
- Never let the browser, return URL, terminal display, email send response, or unauthenticated callback payload become financial/delivery truth. Payment callbacks require authoritative provider lookup. A signed provider delivery event may be authoritative for message delivery when that provider's protocol defines it; persist it immutably and handle duplicates/reordering.
- Store money in integer minor units with an explicit currency. Persist property-local business inputs and UTC audit instants.
- All externally retried commands use stable idempotency identities. Same key/same body replays; same key/different body fails.
- All tenant/property queries, downloads, actions, jobs, webhook endpoints, and exports need explicit isolation tests.
- Card PAN, CVV, expiry, track data, PIN, access tokens, webhook secrets, guest raw tokens, and provider credentials must not enter source control, logs, exceptions, queues, audit payloads, exports, screenshots, or UAT evidence.
- Never refresh or migrate the demo database as a test. PostgreSQL tests must assert `DB_DATABASE=inn_test` before destructive setup.
- A test-only provider simulator proves contract behavior, not physical hardware or production delivery. Completion language must say exactly what was proved.

## Architecture choices that are not agent work

- Keep Inn's durable payment requests, accounting application and audit ledger app-owned. Do not replace them with Frappe Payments.
- Do not add Hyperswitch until at least two live PSPs create measured routing/failover value and a supported connector exists for the selected merchants.
- Do not accept card data in Inn. Online cards stay in hosted Mercado Pago checkout; card-present data stays in approved external/Point terminals.
- QloApps, AureusERP, eStay and Filament plugins are reference material, not the source of truth. Borrow patterns only after checking current behavior, maintenance, framework compatibility, license and security. Never paste code without license-compatible attribution and tests.
- Keep the current custom calendar unless Agent 05 proves a maintained Filament calendar plugin meets the full resource-lane, command, authorization, accessibility and performance contract with less risk. A prettier calendar alone is not a migration reason.
- A plugin may accelerate presentation, but inventory, quote, payment, delivery, authorization and audit invariants remain in Inn application services and database constraints.

## Worktree and branch protocol

For every assignment:

1. Wait until every implementation prerequisite PR is merged.
2. In the root checkout run `git fetch --prune origin`, fast-forward local `main`, and confirm `main...origin/main` is `0 0`.
3. Create a dedicated worktree and the exact branch named in the assignment. Never run two agents in one checkout.
4. Record the base commit in the assignment evidence file before editing.
5. Preserve unrelated changes. Do not copy uncommitted work from another branch.
6. Rebase onto current `origin/main` before final verification; resolve contract/migration/shared-route conflicts intentionally.
7. Push, open a PR, wait for every required CI job, obtain review, and let the coordinator merge. Feature agents do not merge their own PRs.
8. After merge, delete the worktree/branch and synchronize `main` before unlocking dependents.

Bounded pre-work exceptions are explicit, disposable, and never bypass the merge graph:

- Agent 02 may prepare docs/DTO/fixture-only commits in a disposable worktree before Agent 01; cherry-pick them only after creating the real branch from post-Agent-01 `main`.
- Agent 06 may draft the threat model before Agent 04, but cannot freeze schema/OpenAPI, create the named branch, or merge until Agent 04 is on `main`.
- Agent 08 may implement against Agent 06 fixtures before Agent 07, but its final rebase/UAT/PR gate requires Agent 07.
- Agent 13 may draft the ADR/images after Agent 03, but final merge requires the runtime manifests listed in its index row.

## Protected shared files

Only the release coordinator, or Agent 15 acting under the coordinator's explicit review, finalizes these status documents:

- `docs/client-ready-phase-3-plan.md`
- `docs/client-uat-ledger.md`
- `docs/feature-matrix.md`

Feature agents, including payment Agent 00, write evidence into their own assignment file or `docs/evidence/<slice>/README.md`. They may update an existing granular plan for their slice, but never mark another slice complete.

These code files are collision hotspots: `contracts/openapi.yaml`, route files, `AppServiceProvider.php`, migrations/seeders, `compose.yml`, Dockerfiles, environment examples, `Makefile`, CI, shared payment services, and Playwright setup. An agent may edit a hotspot when its assignment requires it, but must list the exact edits in its handoff and rebase before merge. Agent 13 owns the final production-runtime versions of Compose, Docker, CI, and root environment files.

Parallel implementation is integrated serially. Use migration filename namespaces `2026_08_20_<agent-number>xxxx_*` (00, 01, 02, 03, 04, 045 for 04B, 05, 06, 07, 08, 09, 10, 11, 12, 13, 14, 15), shifting the date only if a committed migration already occupies the name. After every merge, the coordinator rebases the next PR and runs a fresh PostgreSQL migrate-from-zero plus aggregate route/OpenAPI verification. Nullable-scope uniqueness must use `NULLS NOT DISTINCT`, expression/partial indexes, or canonical non-null keys as appropriate; ordinary nullable `UNIQUE` is not sufficient.

## Execution graph

```text
00 P3-06A closure and merge
 ├─ 01 P3-06B tenders ────────────────┐
 │                                    └─ 02 P3-06C Point/QR
 ├─ 03 production communications ─────┬─ 12 private storage/privacy (also 01)
 │                                    └─ 09 direct-booking closure
 ├─ 04 commercial rules ── 06 booking contract ┬─ 08 public UX ───────┐
 │                                              └─ 07 domain/API ──────┼─ 09 closure
 ├─ 05 operational acceptance
 └─ 10 integration kernel ─────────────── 11 real connector

03 merged ── 13 production runtime/observability
04B merged or formally deferred ── 13 production runtime ── 14 recovery
02,05,09,11,12,13 merged ── 14 backup/restore/handoff
all required work + decisions ── 15 final client release certification
```

Agents 01, 03, 04, 05, and 10 may begin in parallel only after Agent 00 merges. Agent 05 must rebase/finalize after Agent 04 because companion changes reprice. Agent 06 freezes only after Agent 04. Agent 08 may build against Agent 06’s contract while Agent 07 implements the API, but cannot claim integration completion until rebased on Agent 07. Agent 02 cannot implement against payment internals until Agent 01 merges. Agent 13 may do runtime/ADR work after Agent 03, but it is a late integration branch and must absorb the final queue, storage, environment and health manifests from Agents 02, 09, 10, 11, 12 and conditional 04B before merge. Agents 14 and 15 are deliberately last.

## Assignment index

| Agent | File | Branch | Unlock condition |
|---|---|---|---|
| 00 | `00-p3-06a-provider-closure.md` | existing P3-06A branch | now |
| 01 | `01-p3-06b-front-desk-tenders.md` | `codex/p3-06b-front-desk-tenders` | 00 merged |
| 02 | `02-p3-06c-point-qr.md` | `codex/p3-06c-mercado-pago-point-qr` | 01 merged |
| 03 | `03-p3-04-production-communications.md` | `codex/p3-04-production-communications` | 00 merged |
| 04 | `04-commercial-rules-fiscal-readiness.md` | `codex/p3-commercial-rules` | 00 merged |
| 04B | `04b-conditional-fiscal-invoicing.md` | `codex/p3-fiscal-<jurisdiction>` | 04 plus approved legal inputs; add 10 if using its kernel; otherwise written deferment |
| 05 | `05-operational-acceptance-closure.md` | `codex/p3-operational-acceptance` | work after 00; rebase/merge after 04 |
| 06 | `06-p3-07f-direct-booking-contract.md` | `codex/p3-07f-direct-booking-contract` | threat prework after 00; branch/freeze after 04 |
| 07 | `07-p3-07a-direct-booking-domain-api.md` | `codex/p3-07a-direct-booking-api` | 04 and 06 merged |
| 08 | `08-p3-07b-direct-booking-public-ux.md` | `codex/p3-07b-direct-booking-ux` | 06 merged; final gate needs 07 |
| 09 | `09-p3-07c-direct-booking-closure.md` | `codex/p3-07c-direct-booking-closure` | 03, 07, and 08 merged |
| 10 | `10-p3-08a-integration-kernel.md` | `codex/p3-08a-integration-kernel` | 00 merged |
| 11 | `11-p3-08b-real-connector.md` | `codex/p3-08b-integration-<provider>` | 10 plus the provider-specific dependency row in Agent 11 |
| 12 | `12-p3-05a-private-storage-privacy.md` | `codex/p3-05a-private-storage` | 01 and 03 merged |
| 13 | `13-p3-05b-runtime-observability.md` | `codex/p3-05b-runtime-observability` | work after 03; merge after 02, 09, 10, 11, 12, and in-scope 04B manifests |
| 14 | `14-p3-05c-backup-restore-handoff.md` | `codex/p3-05c-restore-release` | 02, 04B, 05, 09, 11, 12, and 13 merged or formally deferred |
| 15 | `15-final-client-release-certification.md` | `codex/client-release-certification` | all required slices including 04B, or written deferments, merged |

## Decisions the coordinator must make explicit

These are release inputs, not excuses to invent behavior:

| Decision | Latest safe deadline | Default if absent |
|---|---|---|
| Mercado Pago merchant country/currency | decided 2026-08-20 | Colombia/MCO + COP accepted for Agent 00 merge; Argentina/MLA + ARS deferred as regional certification. Merchant credentials/account/environment/site/currency remain connection-scoped. |
| Physical Point device/account and PDV mode | before Agent 02 production claim | ship QR sandbox + Point virtual-test mode; label physical Point not yet client-proven |
| Email provider, verified domain, data-processing approval | before Agent 03 live UAT | Resend adapter; production completion remains gated on verified client domain |
| Fiscal entity/jurisdiction/numbering/tax/cancellation rules | before fiscal issuance | run Agent 04B if fiscal issuance is required; otherwise obtain written deferment and keep P3-03 documents explicitly non-fiscal |
| KPI definitions and sign-off owner | before Agent 05 closes | reconcile provisional formulas, but block “Client-ready” until client approval or written deferment |
| Direct booking launch decision | before Agent 06 merge | in scope, because the durable rule keeps expansion features unless deferred in writing |
| First external integration outcome/provider | before Agent 11 starts | implement a real Standard-Webhooks-compatible outbound connector to an independently hosted receiver; do not call it OTA/accounting sync |
| Hosting region/providers, RPO/RTO, incident owner | before Agent 13/14 closes | write provisional values and require explicit acceptance before production release |

## Universal quality gate

Every feature PR must pass, as applicable:

```bash
make build-api
(cd apps/web && npm run build)
make lint
make contract
make test-api
make test-api-postgres
make test-web
make test-client
make doctor
(cd apps/api && composer audit --locked)
(cd apps/api && npm audit --audit-level=high)
(cd apps/web && npm audit --audit-level=high)
git diff --check
```

Also run focused tests named in the assignment, dependency audits, a credential/secret scan, and the real browser/provider/runtime journey. A skipped provider or PostgreSQL test is acceptable only when the assignment explicitly classifies it and CI runs the non-skipped production-engine equivalent.

## Primary reference index

- [Laravel 13 documentation](https://laravel.com/docs/13.x/documentation), [queues](https://laravel.com/docs/13.x/queues), [scheduling](https://laravel.com/docs/13.x/scheduling), [Horizon](https://laravel.com/docs/13.x/horizon), [cache locks](https://laravel.com/docs/13.x/cache), [rate limiting](https://laravel.com/docs/13.x/rate-limiting), [HTTP client](https://laravel.com/docs/13.x/http-client), [filesystem](https://laravel.com/docs/13.x/filesystem), and [deployment](https://laravel.com/docs/13.x/deployment)
- [Filament 5 testing overview](https://filamentphp.com/docs/5.x/testing/overview), [resource testing](https://filamentphp.com/docs/5.x/testing/testing-resources), [action testing](https://filamentphp.com/docs/5.x/testing/testing-actions), and [security](https://filamentphp.com/docs/5.x/advanced/security)
- [Playwright web servers](https://playwright.dev/docs/test-webserver), [projects](https://playwright.dev/docs/test-projects), and [authentication](https://playwright.dev/docs/auth)
- [WCAG 2.2](https://www.w3.org/TR/WCAG22/), [OpenAPI specification](https://spec.openapis.org/oas/), and [Standard Webhooks](https://github.com/standard-webhooks/standard-webhooks/blob/main/spec/standard-webhooks.md)
