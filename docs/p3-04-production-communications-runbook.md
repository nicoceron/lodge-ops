# P3-04 production communications runbook

## Truth and safety invariants

- `provider_accepted` means the provider accepted the request. Only an authenticated `email.delivered` event sets `delivered_at`.
- Endpoint identity comes from the SHA-256 hash of the opaque URL key, not webhook JSON. Signatures cover the exact raw bytes with Svix ID and timestamp.
- Delivery events are append-only provider facts. Duplicate event IDs are acknowledged once; later terminal facts such as complaints may supersede a prior delivered display state.
- Every send rechecks property-scoped consent and suppression while holding the communication row. Templates cannot select sender headers or undeclared merge fields.
- A retry retains `communication:{uuid}`. A deliberate resend creates a new communication and audit identity. Unknown outcomes beyond the provider's 24-hour idempotency window enter reconciliation instead of being sent blindly.

## Configure a property

1. Verify the sending domain in Resend and complete SPF, DKIM and DMARC review.
2. Store API and Svix secrets in the deployment secret manager under uppercase environment names. The database stores only references such as `env:RESEND_API_KEY` and `env:RESEND_WEBHOOK_SECRET`.
3. As an authorized Inn administrator, create a disabled provider connection for the property. Enter the verified sender domain, exact from/reply-to identities, provider account label and sender-domain allowlist. Use **Verify sender with Resend** to confirm that the configured domain is verified through the referenced API credential, then enable the connection. Copy the opaque endpoint key once into the Resend webhook URL. Changing a sender, domain, account, provider, or API-secret reference invalidates the stored verification and disables the connection until it is verified again.
4. For rotation, add the next webhook secret reference, deploy it as the overlap secret, confirm signed traffic, promote it to primary, then remove the prior reference. Revoke a compromised endpoint by disabling the connection and issuing a new opaque key.
5. Set `COMMUNICATION_LOCAL_FALLBACK=false` in production. Production deliberately fails closed without an enabled verified property mapping.

## Queues and Horizon

Run `php artisan horizon` under the process manager. Required queues are `critical`, `provider-events`, `notifications`, `automations`, `documents`, `reports`, `integrations`, and `default`; `config/horizon.php` isolates latency-sensitive event/send work from document/report work. Redis `retry_after` is 240 seconds, the notification supervisor timeout is 90 seconds, and the send job timeout is 60 seconds. Agent 13 must replace the ordinary Compose `queue:work` worker with this Horizon process when integrating production topology.

On deploy, run migrations, start the new release, and run `php artisan horizon:terminate`; the process manager starts workers on the new code. `horizon:snapshot` runs every five minutes. The Horizon dashboard is disabled externally unless `HORIZON_DASHBOARD_ENABLED=true`, and then requires a user whose system-admin flag is set; a property Administrator membership is insufficient.

- Failed transient job: inspect the safe error and provider state, then `php artisan horizon:forget <id>` only after disposition or `php artisan queue:retry <id>` when retry is safe.
- Poison job: pause the affected queue, record the delivery/event ID and safe exception, move it to manual reconciliation, deploy the correction, then retry only the named job.
- Starvation: compare Horizon wait metrics against configured thresholds; increase the affected supervisor without merging provider events and documents into one queue.
- Provider/Redis outage: leave communications queued, do not enable production fallback, restore the dependency, reconcile uncertain provider attempts, then resume workers.
- Worker death after provider acceptance: retry keeps the same provider idempotency key within 24 hours. After that window the attempt becomes `reconciliation_required`.

## Scheduling and heartbeat

The minute scheduler has a named ten-minute lock and `onOneServer()`. Occurrences persist property-local input, timezone, UTC instant, rule/policy versions and claim identity. PostgreSQL claims use `FOR UPDATE SKIP LOCKED`; jobs are enqueued after commit. If dispatch fails after commit, the occurrence returns to durable pending state, and stale claimed rows are reclaimed after the lease expires, so restart/replay does not strand an occurrence. Amendments supersede pending/claimed old revisions, cancellations/no-shows suppress them, and dispatched history is never rewritten.

DST policy is `dst-shift-forward-ambiguous-standard`: nonexistent local times shift forward; ambiguous times use standard time. A later property timezone change does not rewrite an existing occurrence. New reservation revisions use the current property timezone.

Run `php artisan communications:health` from monitoring. It fails when the `reservation-milestones` heartbeat is absent or stale, a claimed occurrence has exceeded its lease, a persisted delivery event remains pending/failed, or an uncertain attempt has exceeded the provider idempotency window. The same failures appear in the Lodge command-center alert. `communications:sweep-delivery-events` is scheduled every minute to re-enqueue persisted pending/failed events. Also alert on rising `reconciliation_required`, failed `provider-events`/`notifications`, queue waits over Horizon thresholds, or provider complaint/hard-bounce spikes.

## Backfill and rollback verification

The migration maps every legacy milestone to a durable `dispatched` occurrence with the legacy rule key and `legacy-v1` policy so deployment cannot resend historical reminders. Before and after deploy, save these counts:

```sql
select count(*) as legacy from reservation_automation_milestones;
select count(*) as backfilled from reservation_milestone_occurrences where policy_version = 'legacy-v1';
select state, count(*) from reservation_milestone_occurrences group by state order by state;
select count(*) from reservation_milestone_occurrences where target_at is null or timezone is null;
```

`legacy` and `backfilled` must match and the null count must be zero. Rollback first stops scheduler/Horizon, preserves exported occurrence/event/attempt tables for audit, then rolls back the migration. Do not roll back after new external sends without an explicit reconciliation plan.

## Attachment and privacy controls

Only authorized, unexpired, checksum-verified app-generated PDFs may be attached, and the current actor is re-authorized through the generated-document email policy at send time. Uploaded/evidence artifacts remain blocked until Agent 12's clean-scan state is integrated. Logs and normalized webhook payloads contain hashes and safe codes, not recipients, bodies, API keys, webhook secrets or raw provider payloads.

Transactional, operational, and optional purposes are fixed enum values backed by the versioned `communication_purpose_policies` approval ledger. Optional survey and marketing preferences can be withdrawn in the guest portal UI or API. Every destination, including role-derived metadata recipients, is checked by recipient hash against both property and global suppressions immediately before provider submission.

## Activation checklist

Merge readiness and production activation are separate. Activation remains blocked until all are observed: approved provider/DPA, verified client domain, SPF/DKIM/DMARC, client-approved sender mapping, secret-manager credentials, rotation drill, alerts, real recipient inbox plus authenticated delivered event, and controlled bounce/complaint causing suppression. A local Mailpit message or provider acceptance is not delivery evidence.

Primary behavior references: [Laravel mail](https://laravel.com/docs/13.x/mail), [queues](https://laravel.com/docs/13.x/queues), [scheduling](https://laravel.com/docs/13.x/scheduling), [Horizon](https://laravel.com/docs/13.x/horizon), [Resend webhook verification](https://resend.com/docs/webhooks/verify-webhooks-requests), [event types](https://resend.com/docs/webhooks/event-types), [retries and replays](https://resend.com/docs/webhooks/retries-and-replays), and [idempotency keys](https://resend.com/docs/dashboard/emails/idempotency-keys).
