# Agent 13 — P3-05B production runtime, deployment, and observability

## Copy/paste assignment

> Build the production runtime and operational telemetry after P3-04 merges. Read this file, the coordinator README, all Compose/Docker/CI/env/config/health/queue/scheduler code, Laravel deployment/Horizon docs and the selected infrastructure providers’ current primary docs. Replace development servers and bind-mount assumptions with hardened immutable images and separate API/web/worker/scheduler processes, add production preflight/readiness/structured telemetry/alerts, safe migration/deploy/rollback and supply-chain gates. Do not choose legal retention or claim DR—that is Agent 14. Do not place secrets in images, Git or logs.

## Branch and ownership

- Branch: `codex/p3-05b-runtime-observability` may begin after Agent 03. Before final merge, rebase and incorporate the runtime manifests from Agents 02, 09, 10, 11, 12 and in-scope Agent 04B; do not merge a production topology that omits their queues, services, secrets, health checks or storage dependencies. A formally deferred 04B is recorded instead.
- Own final Dockerfiles/Compose production profile, root env contract, process commands, CI supply-chain/runtime gates, deployment scripts/runbook, health/readiness, logging/metrics/tracing and alert definitions.
- This agent finalizes shared runtime files after rebasing all merged queue/config requirements. It must include every named queue, including `provider-events`.
- Read `compose.yml`, Dockerfiles, Makefile, CI, Laravel/Next configs, queue/Horizon/schedule, `/up`, proxies/session/cookie/CORS, filesystems, logs, current `.env.example` files and dependency locks.

## Architecture decision and production processes

- Write an ADR proposing/scoring regions/providers for managed PostgreSQL, Redis, private object storage, secret manager, HTTPS ingress/CDN/WAF, container/runtime platform and telemetry. Score latency to lodge/users, availability, backup/PITR, support, data residency, egress and monthly cost. The client/coordinator must approve the runtime platform, region, cloud accounts/cost, telemetry backend, secret manager and named alert recipient before live provisioning/acceptance; the agent does not unilaterally create a cost/legal commitment.
- API uses a documented production PHP HTTP stack (for example Nginx + PHP-FPM or a reviewed supported equivalent), never `artisan serve`.
- Web builds a production Next standalone artifact and runs `next start`/standalone server, never `next dev`.
- Separate immutable API, web, Horizon worker and singleton scheduler/reverb-if-needed containers. No development bind mounts. Non-root/read-only filesystem/capability drop where viable; explicit writable temp/cache volumes.
- Multi-stage reproducible builds, locked production dependencies, opcache, cached Laravel config/routes/views/events, health checks and version/commit metadata.

## Production preflight and deploy

- Add a capability-aware `production:preflight` command that rejects debug, insecure app/proxy/cookie URL settings, local mail/storage, absent shared Redis, unsafe queue/session/cache drivers, invalid `timeout >= retry_after`, unwritable temp and unsupported DB version/extensions. A secret/provider key is required only when that capability is enabled/required; enabled-without-secret fails, while an explicitly disabled/deferred Point/fiscal/connector does not break unrelated startup.
- Deployment: build/test/audit/SBOM/image scan → backup checkpoint → migrate with `--isolated --force` using expand/migrate/contract discipline → optimize → rollout → Horizon terminate/reload → readiness/smoke/UAT canary.
- Application rollback changes image/config without assuming destructive DB down migrations. Document forward-fix for incompatible migrations and mixed old/new worker compatibility.
- Secrets injected at runtime by reference, least privilege, rotation overlap and audit. Never print config/secrets in preflight logs.
- Choose and document exactly one production scheduler model: singleton `schedule:work` process or platform cron invoking `schedule:run`. Prove singleton behavior, overlap-lock backend and deployment restart for the selected model.

## Health and telemetry

- Liveness is process-only/lightweight. Internal readiness checks DB, Redis and object storage with strict timeouts; external payment/email outages degrade capability but do not kill the entire app.
- Operational health covers scheduler heartbeat, queue wait/backlog/failed jobs, provider event backlog, stuck refunds/deliveries, integration lag/dead letters, storage scanner health, backup age and DB/storage availability.
- Structured JSON logs with request/correlation, tenant/property, actor/service identity, job/attempt/provider-event/integration-run IDs and safe error class. Redact authorization/cookies/tokens/raw webhook/email bodies/document content/payment-sensitive/PII.
- Metrics/traces for HTTP latency/error, DB/query, queue wait/runtime/failure, scheduler, provider HTTP, email events, payment/refund/settlement exception and integration lag. Bound label cardinality; no guest IDs/emails as metric labels.
- Alerts have severity, threshold/window, owner, destination, dedup/runbook link and recovery condition. Prove scheduler stall, queue backlog, failed provider job and readiness failure alerts.

## Tests and release gates

- Production-like environment boots with cached config and no dev dependencies/mounts; `/manage`, API, public booking and web assets pass through HTTPS/proxy headers.
- Readiness/liveness behavior under DB, Redis, object storage and provider outage.
- Rolling deploy/worker restart during queued payment/email/document/integration jobs loses and duplicates nothing; old/new compatibility passes.
- Singleton scheduler across replicas and stale heartbeat alert.
- Security headers, TLS/proxy, secure cookies/session, CORS/CSP, filesystem permissions, non-root and read-only checks.
- Logs/traces correlate a client journey and pass automated secret/PII redaction tests.
- Generate SBOM, dependency/credential scans and image vulnerability report; no unresolved critical/high issue without dated accepted risk.
- Rebase after Agents 10 and 12 and after every other required runtime manifest named in the README. Run universal gates in production-like mode plus Docker smoke and explicit ordinary worker queue/service/secret/health manifest tests.

## Primary references

- [Laravel deployment](https://laravel.com/docs/13.x/deployment), [configuration](https://laravel.com/docs/13.x/configuration), [logging](https://laravel.com/docs/13.x/logging), [queues](https://laravel.com/docs/13.x/queues), and [Horizon](https://laravel.com/docs/13.x/horizon)
- [Next.js production checklist](https://nextjs.org/docs/app/guides/production-checklist)
- [Docker build best practices](https://docs.docker.com/build/building/best-practices/)
- [OWASP Secrets Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Secrets_Management_Cheat_Sheet.html) and [Logging Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html)
