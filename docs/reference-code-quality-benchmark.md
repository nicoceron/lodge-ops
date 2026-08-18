# Reference and code-quality benchmark

Audit date: 2026-08-18  
Purpose: preserve reproducible inspiration without importing another product's architecture or unreviewed source

## Benchmark method

Each open-source reference is pinned to the commit inspected on the audit date. The review checked license, runtime compatibility, tests, CI/static-analysis signals, domain fit, and the boundary between a reusable pattern and a competing source of truth. The source repositories are not vendored into Inn. Small literal reuse is allowed only when the license permits it, attribution is retained, the exact source commit/path is recorded, and Inn-specific tests cover the rewritten behavior.

Inn's own minimum bar remains higher than a repository popularity score:

- PHP 8.3+, Laravel 13, Filament 5, PostgreSQL, Redis, and the checked-in OpenAPI contract.
- Pint, PHPStan, PHPUnit, ESLint, TypeScript, production build, dependency audits, PostgreSQL CI, Docker smoke, and authenticated state-changing Playwright.
- Tenant and role isolation, integer money, half-open inventory intervals, database locks, immutable snapshots, append-only money/history, after-commit delivery, idempotency, and observable failure/replay.

## Pinned reference register

| Reference | Pinned revision and license | Quality evidence observed | Inn decision |
| --- | --- | --- | --- |
| [AureusERP](https://github.com/aureuserp/aureuserp/tree/4906b273aa4eef5fd21f9b0f92435e3acfd86333) | `4906b273aa4eef5fd21f9b0f92435e3acfd86333`; MIT | Laravel 13 / Filament 5; 214 tracked test/support files; Pest CI on MySQL and PostgreSQL; sharded Playwright on both databases. Broad and actively developed, but much larger than Inn. | Best code-pattern reference for Filament resource organization, transactional actions, accounting document lifecycles, report filters/exports, and multi-database journey coverage. Do not adopt its ERP module graph, accounting as reservation state, or non-Inn money/tenant assumptions. |
| [QloApps](https://github.com/Qloapps/QloApps/tree/a1ceb1b2a9ae2c0b362ecf5721ddc3260a12c03c) | `a1ceb1b2a9ae2c0b362ecf5721ddc3260a12c03c`; OSL-3.0 | Large hotel-specific codebase with more than 8,500 tracked files, but no current GitHub Actions workflow at the pinned revision and only a small legacy test harness in the repository. | Strong workflow oracle for availability-first booking, booking workbench, advance payment, room allocation/moves, booking detail, and channel operations. Do not copy literal source into Inn; reimplement the behavior against Inn services. |
| [Guava Calendar](https://github.com/GuavaCZ/calendar/tree/2527939b38976ed745477c89a7297d58df47732f) | `2527939b38976ed745477c89a7297d58df47732f`; MIT | PHP 8.2+, Filament 5, Laravel 12/13 test matrix, Pest, PHPStan, Pint, event edit-gating and timezone tests. | Strongest calendar-package candidate if a named gap defeats the native calendar. Keep it out until a disposable spike passes Inn resource lanes, roles, mobile, performance, and safe mutation gates. |
| [Saade Filament FullCalendar](https://github.com/saade/filament-fullcalendar/tree/c5037e82d60c3b59329f9761fe36ce1c3d4a74f9) | `c5037e82d60c3b59329f9761fe36ce1c3d4a74f9`; MIT | Declares Filament 4/5 and Laravel 10–13 compatibility, PHPStan and a broad CI matrix, but the pinned tree contains no tracked package tests even though the workflow invokes Pest. FullCalendar resource scheduling can also introduce a separate premium license. | Fallback rendering spike only. It is not the current code-quality benchmark for core inventory behavior. |
| [Filament Apex Charts](https://github.com/leandrocfe/filament-apex-charts/tree/40f6715aff927fd03f25c1c227d3086959cf84bf) | `40f6715aff927fd03f25c1c227d3086959cf84bf`; MIT | Filament 4/5, Laravel testbench 9–11, Pest, PHPStan, Pint, and CI. | Optional presentation layer after KPI definitions and exports are correct. Chart code may never become metric authority. |
| [Filament Language Switch](https://github.com/bezhanSalleh/filament-language-switch/tree/faaafae9a8edebb6163355ca3caea4dfcffd25d2) | `faaafae9a8edebb6163355ca3caea4dfcffd25d2`; MIT | Laravel 11–13, Filament 4/5, Pest architecture/type coverage, PHPStan, Pint, and Rector scripts. | Low-risk staff locale selector only after real Spanish/English translations, document templates, and communication variants exist. It is not the localization implementation. |
| [Frappe Payments](https://github.com/frappe/payments/tree/781c21ca77bf3f3ced21cad5a515d4090743188f) | `781c21ca77bf3f3ced21cad5a515d4090743188f`; MIT | Active Frappe 17 / Python 3.14 app; provider SDKs and a MariaDB-backed CI suite; the pinned head adds Razorpay refund/webhook work. | Useful provider-flow reference, not Inn's payment engine. Adopting it would add Frappe/Python, DocTypes, web-form overrides, another deployment, and a distributed transaction boundary. Inn keeps Laravel and its payment/folio domain authoritative. |
| [eStay admin/public demo](https://estay.wrteam.me/) | Proprietary demo; no code license granted | A local authenticated visual capture exists outside this repository at `/Users/ceron/Developer/Projects/framer-html-exporter/exports/estay-filament` with 50 HTML routes and 309 files (137 MB). A separate single-page capture exists at `/Users/ceron/Developer/Projects/framer-html-exporter/exports/estay-export`. Browser captures cannot prove or recover server policies, database constraints, jobs, or provider correctness. | UX oracle only: compact live price, booking detail, payment timeline, arrivals/departures, room-grid availability, and separate provider configuration. Never copy hidden APIs, credentials, or proprietary source. |
| [Filament reporting templates](https://filamentanalytics.com/reporting-templates) | Commercial/template offering | Useful visual examples, not a necessary runtime package. | Use Filament 5 native queued exports and Inn KPI services first. |
| [StateFusion](https://filamentphp.com/plugins/assem-alwaseai-statefusion) | Third-party Filament plugin | Adds another state-machine abstraction. | Reject for core reservation/payment state. Inn already has explicit enum/service transitions and tests. |

## Pattern study list

The following are pattern candidates, not drop-in architecture:

- AureusERP accounting workflow tests under `plugins/webkul/accounts/tests/Feature/Workflows/`: useful lifecycle and invariant test shape for invoices, credit notes, payment terms, company isolation, and posting restrictions.
- AureusERP API and Filament tests under `plugins/webkul/accounts/tests/Feature/`: useful allow/deny matrices and resource behavior.
- AureusERP CI: useful dual-database and sharded browser structure. Inn remains PostgreSQL-authoritative and does not need MySQL parity unless a deployment requirement appears.
- QloApps booking/order documentation and UI: derive client UAT cases for availability, advance payment, room assignment, move/swap, reservation detail, and channel health.
- Guava calendar tests: useful editability gating, event resolution, context-menu, and timezone acceptance ideas if the rendering spike is reopened.

## Adoption gate

No package or snippet is adopted until a disposable branch proves:

1. dependency resolution against locked Inn versions;
2. migration, policy, tenant-query, storage, queue, and license review;
3. no second money, inventory, audit, communication, or state authority;
4. failure, uninstall, rollback, and upgrade behavior;
5. automated domain and state-changing browser evidence for the named requirement it solves.

This benchmark is saved as provenance. It is not evidence that Inn implements a referenced feature.
