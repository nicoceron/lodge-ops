# Agent 03 — P3-04 production communications and scheduling

## Copy/paste assignment

> Implement production communications and deterministic property-local scheduling from synchronized `main` after P3-06A merges. Read this file, the coordinator README, Rincón Grande RG-4.4/RG-4.10, current communication/outbox/automation/milestone code and Laravel/Resend primary docs completely. Use a provider contract with a Resend reference adapter, signed raw-body event ingestion, immutable delivery events, suppressions, exact-once scheduled occurrences, visible failure/reconciliation queues, Horizon supervision, and real inbox/bounce evidence. A provider accepting a send is not proof of delivery. Do not mark `delivered_at` until an authenticated delivered event says so.

## Branch and ownership

- Branch: `codex/p3-04-production-communications` after Agent 00.
- Own communication provider contract/adapter, a dedicated `communication_provider_connections` identity, delivery attempts/events, consent/preferences/suppressions, milestone occurrences, jobs/queues/scheduler/Horizon app configuration, Filament/API/OpenAPI/tests/runbook. Do not migrate or extend the shared `integration_connections` schema owned by Agent 10.
- Coordinate root Compose/CI production topology with Agent 13; record required queues/processes in the handoff rather than overwriting Agent 13’s parallel branch.
- Read: `CommunicationDeliveryService`, `CommunicationMail`, `DeliveryAttempt`, `Communication`, `CommunicationSuppression`, `OutboxRecorder`, `OutboxBatchPublisher`, automation services/rules, `DispatchReservationMilestones`, `ReservationAutomationMilestone`, relevant Filament resources/tests, queue/mail/services config, route/schedule files.

## Provider and delivery truth

- Define capability-focused communication contracts and a Resend adapter. Resolve credentials by secret reference; no DB plaintext secret.
- Map property → provider account/verified domain/from/reply-to with allowlists and safe disabled/fallback behavior. Never accept arbitrary sender headers from a template or request.
- Distinguish queued, provider-accepted, sent, delivered, delayed, soft-bounced, hard-bounced, complained, suppressed, rejected, failed, and reconciliation-required.
- Extend attempts with provider connection/account, provider message ID, stable Inn idempotency key, safe request checksum, accepted/sent/delivered/failure times, normalized safe error and retry/reconciliation state.
- Add immutable `communication_delivery_events`: tenant/account, provider event/message IDs, type, occurred/received/processed times, raw-body checksum, safe normalized payload, processing state/error, uniqueness.
- Signed webhook uses exact raw request bytes and Svix headers. Tenant/account comes from a hashed opaque endpoint key mapped locally, never payload claims. Keys are rotatable/revocable with overlap and generic failure responses.
- Persist valid event quickly, acknowledge, then process asynchronously on an explicit queue. Duplicate/reordered events are idempotent and state transitions are monotonic except later terminal facts such as complaint after delivery.
- Hard bounce, complaint and provider suppression create/update a suppression before later sends. Recheck suppression inside the delivery job immediately before the provider call.
- Queue sends only after business transaction commit. An uncertain timeout retries with the same provider idempotency key; after its supported window, route to reconciliation rather than blind resend.
- Separate “retry this delivery” from “create a new resend” with different audited identities.
- Classify each communication purpose as transactional, operational/internal or optional marketing. Persist consent/preference source, version, time and channel; implement marketing unsubscribe/withdrawal separately from provider bounce/complaint suppression. Transactional/operational exceptions require an explicit approved policy, not a hidden consent bypass.

## Templates, attachments, and operator UI

- Strict merge-field allowlist per template; reject missing/unknown fields and escape untrusted content. Version template/locale/subject/body checksum.
- Preview with deterministic fixture data, authorized test-send with visible test marker, and audit actor/recipient/provider ID.
- Before Agent 12, attach only integrity-verified, authorized, unexpired app-generated documents. After Agent 12, adopt its clean-scan gate for uploaded/evidence artifacts; missing/quarantined/pending attachment fails safely.
- Filament: communication/attempt/event/suppression views, failed/reconciliation queue, preview/test-send/retry/new-resend/unsuppress actions with explicit policies and property scoping.
- Deliver confirmation, proposal/payment request, payment/refund receipt, pre-arrival reminder, checkout/folio, and survey invitation through the same pipeline.
- Deliver minimized internal notices to the assigned Guide, Kitchen, Host/Operations and Finance only when their role/journey requires them; never include folio, payment or unrelated guest details by default.

## Deterministic scheduling

- Replace broad UTC scans with durable occurrences storing rule/policy version, property-local input, UTC `target_at`, state, claim token/time, attempts/error and supersession reason.
- Reservation amendments supersede pending occurrences and create versioned replacements. Cancellation suppresses pending work without rewriting dispatched history.
- Migrate/backfill existing `reservation_automation_milestones` into occurrences with a deterministic dispatched/pending/superseded mapping. Preserve historical dispatch identity so deployment neither resends old reminders nor loses pending work; provide rollback/verification counts.
- Define DST policy for nonexistent/ambiguous wall times and test spring-forward/fall-back. Preserve both local input and computed UTC instant.
- Claim due rows transactionally (`SKIP LOCKED` or equivalent PostgreSQL pattern), then enqueue after commit. Worker crash, two scheduler nodes, and replay must dispatch once.
- Use named scheduler locks with explicit expiry and `onOneServer()`. Add scheduler heartbeat and stale-heartbeat alert surface.

## Queue supervision

- Install/configure Horizon for Redis with separate `critical`, `provider-events`, `notifications`, `automations`, `documents`, `reports`, and `integrations` queues; explicit timeouts, retry/backoff, wait thresholds and failure policy.
- Authorize Horizon dashboard to system Admin only. Schedule `horizon:snapshot` every five minutes.
- Document deploy restart (`horizon:terminate`/reload), failed-job retry, poison message handling, queue starvation, and outage procedure.
- Ensure job timeout remains below queue `retry_after`; prove worker termination/restart does not duplicate a provider send.

## Tests and real acceptance

- Contract fixtures: acceptance, delivered, delayed, bounce, complaint, rejected, malformed; 429, 5xx, network timeout, provider idempotency conflict.
- Signature: valid, invalid, missing, stale, malformed raw body; unknown account/message; tenant mismatch; duplicate/reordered event.
- Race/crash: two send workers, provider accepts then local crash, suppression after enqueue, cancel/amend between occurrence claim/send, two scheduler nodes, job crash after claim.
- Date coverage: before/at/after target, leap day, both DST transitions, property time zone change policy.
- Filament authorization/tenant/property isolation and sensitive-log redaction.
- Browser UAT: preview → test send → real inbox delivery → provider delivered event → status; controlled bounce/complaint → suppression → resend blocked; scheduled pre-arrival and survey send once.
- Separate merge and activation evidence. Merge gate: deterministic adapter/signature/concurrency/browser/provider-test-domain proof. Production activation gate: approved provider/DPA, verified client domain, SPF/DKIM/DMARC, client sender mapping, secret-manager credentials, real recipient/provider-event evidence, credential rotation and alert evidence. Missing activation evidence is reported, not hidden, and blocks final client-ready certification rather than unrelated code merges.
- Run universal gates, PostgreSQL race tests, provider contract tests, Docker ordinary worker/scheduler UAT, and audits.

## Primary references

- [Laravel 13 Mail](https://laravel.com/docs/13.x/mail), [queues](https://laravel.com/docs/13.x/queues), [scheduling](https://laravel.com/docs/13.x/scheduling), and [Horizon](https://laravel.com/docs/13.x/horizon)
- [Resend Laravel](https://resend.com/docs/send-with-laravel), [event types](https://resend.com/docs/webhooks/event-types), [webhook verification](https://resend.com/docs/webhooks/verify-webhooks-requests), [retries/replays](https://resend.com/docs/webhooks/retries-and-replays), and [idempotency](https://resend.com/docs/dashboard/emails/idempotency-keys)
- [Filament 5 action testing](https://filamentphp.com/docs/5.x/testing/testing-actions) and [security](https://filamentphp.com/docs/5.x/advanced/security)
