# Agent 12 — P3-05A private storage, malware handling, retention, and privacy

## Copy/paste assignment

> Replace development-only artifact/evidence storage with production-grade private object handling after P3-06B and P3-04 merge. Read this file, the coordinator README, P3-02/P3-03/P3-06 evidence/document code, Laravel filesystem docs and OWASP upload guidance. Make every artifact disk configurable, add quarantine-first scanning with a real scanner adapter, integrity/retention/legal-hold/privacy workflows, authorized downloads and real S3-compatible/ClamAV CI. A four-kilobyte signature check is not antivirus. Scanner outage fails closed. Do not expose object keys or public storage URLs.

## Branch and ownership

- Branch: `codex/p3-05a-private-storage` after Agents 01 and 03.
- Own document/export/payment-evidence/guest-upload storage abstraction, artifact metadata, malware scanner contract/adapter, quarantine/promotion/purge, privacy requests, related Filament/jobs/tests/runbook.
- Coordinate object service/process changes with Agent 13; this agent owns application semantics and integration-test services/fixtures.
- Read generated document/report export models/services/downloads, guest evidence storage/scanner, email attachments, filesystem config/env, purge command/schedule and storage tests.

## Storage and integrity model

- Inventory every existing generated document, report export and `GuestPaymentEvidence` row/object before schema/storage cutover. Record counts, bytes, checksums, missing/corrupt paths and legacy rows without scan metadata.
- Migrate through dual-read/copy/checksum-verify/cutover: old authorized downloads remain available while objects copy; new writes target the new private store; failed copies enter reconciliation. Rollback must preserve new objects and restore a compatible read path rather than losing post-cutover uploads.
- Legacy app-generated artifacts may be marked integrity-verified only after checksum/type validation under an explicit migration policy. Legacy guest/evidence uploads remain quarantined/unavailable until real scan or approved manual disposition.
- Remove hardcoded `local`/path assumptions. Every stored artifact records disk/provider, opaque object key, tenant/property prefix, original safe name, detected MIME, size, SHA-256, created/scanned/promoted/expires/purged timestamps, scan state/result/version and retention/legal hold.
- Private S3-compatible bucket, block public ACL/policy, encryption at rest, versioning where selected, least-privilege service identity and separate quarantine/clean prefixes or buckets.
- Authorized application downloads are the authority. If temporary object URLs are used, issue only after policy/integrity/scan/expiry checks with short TTL and response headers; never persist/share raw object URL.
- Stream uploads/downloads/checksums. Validate server-side magic bytes, extension/content agreement, allowlisted MIME, size, image/PDF structural limits and decompression/archive bomb limits.
- Upload lifecycle: pending/quarantined → scanning → clean/promoted, infected/rejected, or failed/retryable. Bytes remain inaccessible until clean. Scanner outage or unknown result fails closed.
- `MalwareScanner` contract with a real ClamAV or approved managed adapter, version/signature metadata, timeout/retry and health. EICAR is test-only and never leaves controlled fixtures.
- Verify checksum on upload, promotion and download. Missing/corrupt object enters operational reconciliation; do not silently regenerate immutable evidence.

## Retention and privacy

- Versioned retention policy by artifact class/property/jurisdiction; legal hold prevents purge. Scheduled purge deletes bytes idempotently but keeps minimal immutable audit metadata/checksum/reason.
- Privacy request workflow: requested, identity/authority review, scope, export generated privately, approved redaction/pseudonymization, completed/rejected and audit.
- Preserve financial, tax, safety and audit facts required by approved retention; pseudonymize linkable guest fields where lawful instead of corrupting ledgers.
- Legal bases/periods are client-approved configuration. Do not hardcode a legal assertion or claim GDPR/local-law completion without review.
- Filament queues for pending/failed scans, quarantine, corrupt/missing objects, expiry/legal holds and privacy requests, all role/property scoped.

## Tests and acceptance

- Real S3-compatible service in CI: multipart/stream upload, private ACL, authorized download, expired URL, wrong tenant/property, missing/corrupt/versioned object.
- Real scanner service: clean, EICAR infected, timeout, unavailable, malformed response, retry/exhaustion; infected/unknown bytes never preview/download/email.
- MIME spoof, double extension, oversize, malformed PDF/image, archive/decompression bomb fixture and Unicode filename sanitization.
- Concurrent upload/promote/purge/download/legal hold; replayed jobs and checksum mismatch.
- Existing-object migration: interrupted copy/resume, dual-read, missing source, checksum mismatch, rollback after new writes and final old-store decommission counts.
- Browser mobile upload: pending → scanned clean → Finance authorized preview; Operations/Viewer/other property denied. EICAR remains quarantined.
- Privacy export/redaction and purge preserve audit. Email attachment refuses quarantined/expired object.
- Run universal gates, focused PostgreSQL/job tests, real MinIO/S3-compatible and ClamAV integration, audits and secret scan.

## Primary references

- [Laravel filesystem](https://laravel.com/docs/13.x/filesystem) and [queues](https://laravel.com/docs/13.x/queues)
- [OWASP File Upload Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html)
- [OWASP Cryptographic Storage Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html)
- [ClamAV documentation](https://docs.clamav.net/)
