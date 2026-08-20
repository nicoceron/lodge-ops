# Agent 14 — P3-05C backup, restore, disaster recovery, and handoff

## Copy/paste assignment

> Finalize recoverability only after Agents 02, 04B, 05, 09, 11, 12 and 13 are merged or each omitted capability has a written deferment; these prerequisites transitively include their dependencies. Read this file, the coordinator README, production/storage architecture, every runbook and provider backup/PITR/versioning doc. Establish accepted RPO/RTO, coordinate database and object checkpoints, restore into a fresh isolated environment, reconcile business/storage/queue invariants, run the full state-changing client journey there, rehearse failed deploy/secret/provider scenarios, and produce operator handoff. Never test restore over the active environment and never call a backup successful until restoration and application reconciliation pass.

## Branch and ownership

- Branch: `codex/p3-05c-restore-release` after Agents 02, 04B, 05, 09, 11, 12 and 13 or recorded deferments.
- Own backup/checkpoint manifests, restore/reconciliation tooling, DR/deploy/rollback/incident/rotation/privacy handoff, recovery tests/evidence and production acceptance checklist.
- Coordinate master status updates with the release coordinator; this feature agent does not independently rewrite claims.

## Recovery design

- Record business-approved RPO/RTO, recovery owner, escalation, region/account boundaries and assumptions. If approval is absent, label provisional and block production certification.
- Use managed PostgreSQL automated backup + PITR and object versioning/replication/lifecycle as selected. Encrypt in transit/at rest with separately recoverable key references.
- Create immutable backup checkpoint manifest: environment, DB checkpoint/time/LSN-equivalent, object inventory/version cutoff, application/schema version, secret/key versions, counts/checksums and redacted provider backup IDs.
- Protect manifests/backup deletion with separate privilege/MFA/account where supported. Test restore credentials without exposing them.
- Restore into a new isolated network/database/bucket/secret namespace. Never overwrite production or point tests at the demo DB.
- Restored API/worker/scheduler starts in external-side-effect quarantine: outbound email/payment/refund/fiscal/integration calls and ordinary queue consumption are disabled. Never restore production provider credentials into rehearsal; inject sandbox-specific secrets and register new callback endpoints.

## Reconciliation after restore

- Verify migrations/schema/application version and tenant/property/domain row counts.
- Verify every required active document/export/evidence object exists, checksum matches and clean/quarantine/expiry state is coherent.
- Reconcile reservations/allocation conflicts, folio debits/credits, payments/refunds/chargebacks, deposits, settlement revisions, cash shifts, voucher usage and booking orders.
- Inspect pending/claimed jobs, outbox, provider events, communication occurrences/events, integration runs/items/dead letters and idempotency records. Expire stale leases safely; do not replay external side effects blindly.
- Explicitly test DB-ahead/object-behind and object-ahead/DB-behind; fail the restore gate with actionable reconciliation rather than hiding missing facts.
- Provider truth after outage is fetched/reconciled before retrying payment/refund/email/integration mutations.
- Reconcile every pending external item while quarantined, assign safe dispositions, then selectively release named queues/capabilities. Prove production and restore environments cannot process the same lease/event/refund/delivery concurrently.

## Required rehearsals

- Full restore from latest backup/PITR into fresh environment; record actual RPO/RTO with timestamps.
- Failed/incompatible migration after rollout; app image rollback plus forward database fix.
- Unavailable/rotated secret and old key version; emergency rotation and revocation.
- Lost worker after provider success; duplicate/reordered events after recovery; scheduler catch-up without duplicate sends.
- Corrupt/missing object, scanner unavailable, Redis loss and queue rebuild policy.
- Accidental logical deletion recovered to a point before deletion without discarding later evidence; document limitations.
- Entire client journey on restored environment: staff booking → payment/refund → stay/tasks/kitchen → documents/email → direct booking → integration → reports, role denials and phone view.
- External-side-effect quarantine journey: restore boots with no outbound request, sandbox callbacks/secrets are configured, reconciliation finishes, selected queues are released, and no production recipient/account is touched.

## Handoff artifacts and gate

- Step-by-step deploy, rollback, backup, restore, DR, incident, queue/provider reconciliation, secret rotation, privacy, user/role provisioning and vendor escalation runbooks.
- Inventory of service/account owners, least privileges, billing/renewal, domains/certificates, alert routes and support contacts without embedding secrets.
- Restore evidence includes commands/tool versions, sanitized manifest, timings, reconciliation output, UAT run/commit and unresolved risks.
- Schedule recurring automated backup checks and at least quarterly full restore rehearsal; stale backup/failed restore alerts reach a named owner.
- Run universal gates against the restored environment, dependency/image/credential scans and full authenticated/public Playwright.
- Do not mark production-ready until achieved RPO/RTO are accepted, restore/UAT passes, and every runbook has an owner.

## Primary references

- [PostgreSQL 18 backup and restore](https://www.postgresql.org/docs/18/backup.html)
- [Redis persistence](https://redis.io/docs/latest/operate/oss_and_stack/management/persistence/) and [security](https://redis.io/docs/latest/operate/oss_and_stack/management/security/)
- [Laravel deployment](https://laravel.com/docs/13.x/deployment) and [queues](https://laravel.com/docs/13.x/queues)
- Read the selected managed database/object/secret providers’ official PITR, versioning, restore and regional-failure docs on implementation day.
