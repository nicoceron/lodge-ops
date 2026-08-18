# P3-03 documents and exports implementation plan

Date: 2026-08-18  
Status: implementation in progress; WP-00 and WP-01 complete  
Branch: `codex/p3-03-documents-exports`  
Baseline: `main` / `origin/main` at `4bbc72f`  
Parent roadmap: [Client-ready phase 3 plan](client-ready-phase-3-plan.md)  
Release evidence: [Client UAT ledger](client-uat-ledger.md)

## Agent contract

This is the authoritative execution plan for P3-03. An implementation agent must:

1. Work only on `codex/p3-03-documents-exports`, which starts from `4bbc72f`.
2. Keep document generation, artifact access, email attachment intent, and report exports in this slice. Do not absorb production email-provider events, production queue supervision, deployment, online payment gateways, direct booking, or external integrations.
3. Use the existing reservation, payment, refund-change, folio, guest-portal, tenancy, audit, outbox, and idempotency boundaries. Do not create parallel accounting or reservation truth.
4. Treat the PostgreSQL database as the authority for lifecycle transitions and deduplication. Cache locks are a second layer, never the only correctness mechanism.
5. Generate actual PDF/CSV/XLSX bytes and open them in automated acceptance. A record with a `.pdf` path is not evidence of a generated PDF.
6. Preserve synthetic financial and audit history. Tests use `inn_test`; no test command may refresh the local demo database.
7. Update the checklist and evidence table in this document as work lands. Do not mark a requirement complete from source inspection or a render-only smoke test.

## Client-visible outcome

At the end of P3-03, an authorized user can:

- generate and download a reservation confirmation and itinerary;
- generate and download an interim or final folio statement;
- generate a receipt for a reconciled payment;
- generate a refund receipt for a completed refund;
- generate the acknowledged waiver copy associated with the stay;
- queue, monitor, retry, download, and expire operational and finance exports in CSV or XLSX;
- send an existing generated artifact through the current local communication/outbox flow without exposing its storage path; and
- see who requested, generated, downloaded, retried, replaced, or queued delivery of an artifact.

The product must continue to call these artifacts non-fiscal documents. A tax invoice, fiscal receipt, or legally numbered credit note is not in scope until the client confirms the issuing entity, jurisdiction, tax fields, numbering authority, cancellation rules, and fiscal provider.

## Confirmed baseline and gaps

| Existing surface | Current truth | P3-03 replacement |
| --- | --- | --- |
| `DocumentService::store()` | Accepts caller-supplied bytes, writes them to the fixed `local` disk with a `.pdf` extension, and immediately inserts a `generated` record | Immutable snapshot request, real renderer job, private configurable disk, verified bytes and final immutable artifact |
| `generated_documents` | Immutable audit row with template/reservation/guest, `storage_path`, `checksum`, status, signature time and metadata | Remains the immutable successful artifact; add source, renderer, file, replacement, retention and subject facts |
| `DocumentTemplate` | Tenant-versioned JSON definition and one active version per kind | Keep versioning; validate a constrained schema and render only trusted Blade components |
| `ReportExport` | Read-only ledger with kind, filters, status, path, row count and completion | Canonical queued export request/artifact with CSV/XLSX format, private file facts, attempts, failure, expiry and retry |
| `SafeCsvExporter` | Streams CSV and protects formula-leading values after control/space prefixes | Reuse and expand tests; apply the same cell protection to XLSX writers |
| Generated-document Filament resource | Configuration-only visibility and raw private path displayed | Kind-aware role visibility, request/retry/download/email/replace actions, no raw path |
| Report-export Filament resource | Finance-only read-only list with no request or download action | Definition-driven request form, progress/failure state, authorized download, retry and expiry |
| Guest portal | Acknowledges required document text but does not provide generated stay artifacts | Add authorized generated-document list/download for the active guest stay |

## Resolved architecture decisions

### AD-01 — separate request lifecycle from immutable artifact

Add `document_generation_requests` for `pending`, `processing`, `generated`, and `failed` lifecycle state. Keep `generated_documents` immutable and create it only after successful rendering and storage. Do not relax the current global update/delete guard on `GeneratedDocument`.

### AD-02 — snapshots render, live models do not

Each request stores a canonical JSON `source_snapshot`. The renderer receives that snapshot and the selected template version; it must not query live reservation/payment/folio state while rendering. This prevents a retry from silently producing different business content.

Store two hashes:

- `source_checksum`: SHA-256 of canonical source JSON plus kind, locale and template version. It is deterministic and participates in deduplication.
- existing `generated_documents.checksum`: SHA-256 of the generated artifact bytes. It proves file integrity but is not assumed to be identical across renderer versions.

### AD-03 — renderer and dependency choice

Add direct Composer dependencies:

- `spatie/laravel-pdf:^2.12`
- `dompdf/dompdf:^3.1`
- `openspout/openspout:^4.32` because application code will use it directly for XLSX output rather than relying on Filament's transitive dependency.

Use Spatie Laravel PDF behind an Inn-owned `DocumentRenderer` interface. P3-03 defaults to the DOMPDF driver because these documents are static text/tables and do not require browser JavaScript. Keep remote fetching disabled, restrict the renderer filesystem root, package fonts/assets locally, and never render arbitrary user HTML.

The real-render acceptance gate uses the same DOMPDF driver. Gotenberg remains a compatible future driver if visual acceptance proves DOMPDF insufficient; switching drivers is not a reason to change snapshots or business services.

### AD-04 — private object abstraction

Add a `documents` filesystem disk configured by `DOCUMENT_DISK`, defaulting to Laravel's private local storage in development/test. Store the disk name and opaque path on every artifact. P3-05 may point the same disk at S3-compatible private object storage without changing domain code.

Every download must first pass an Inn policy. The controller may stream from the disk or return a very short-lived disk URL after authorization. Filament, API, email metadata, logs and audit UI must never expose `storage_path`.

### AD-05 — queue correctness and privacy

`GenerateDocument` and `GenerateReportExport` must:

- implement `ShouldQueue` and `ShouldBeEncrypted`;
- carry only the request UUID, not the guest snapshot;
- dispatch with `afterCommit()`;
- define queue, timeout, attempts, backoff and `retryUntil()`;
- use `WithoutOverlapping` with an explicit `expireAfter()` value; and
- lock and transition the request row transactionally before and after rendering.

The database unique key is authoritative when two requests or workers race. A worker killed after file creation but before database completion must reconcile or remove the orphan safely on retry.

### AD-06 — one canonical export ledger

Keep Inn's existing `report_exports` table as the canonical report request and artifact. Do not add a second user-facing export ledger in P3-03.

Use Filament actions and its official security guidance, but implement named cross-domain reports through `ReportExport`, `GenerateReportExport`, `SafeCsvExporter`, and OpenSpout. Native Filament `ExportAction` may be introduced later for direct model-table exports only if it is linked to the same Inn tenant/requester/audit boundary.

### AD-07 — reference-code boundary

- Borrow QloApps document types, hotel-oriented content sections, customer-owned voucher access and filename concepts. Do not copy its OSL-3.0 implementation, synchronous TCPDF flow, direct `readfile()` handling, or `MAX + 1` numbering.
- Borrow AureusERP's Filament preview/print/send interaction and credit-document state vocabulary. Do not copy its public-disk PDF storage, synchronous generation, date-only filenames, or unscoped export implementation.
- eStay remains a UI oracle only. Captured browser assets cannot establish backend authorization, queue, storage, or accounting correctness.

## Requirement matrix

| ID | Requirement | Executable proof |
| --- | --- | --- |
| P3-03-DOC-01 | Request lifecycle | Request transitions `pending → processing → generated`; terminal failure records bounded error and can be retried without replacing the original snapshot |
| P3-03-DOC-02 | Snapshot determinism | Canonical snapshot and `source_checksum` are identical for equivalent data regardless of associative-array insertion order |
| P3-03-DOC-03 | Actual PDF | Stored bytes start with `%PDF-`, have `application/pdf`, parse successfully, contain expected text, and render to at least one non-blank page |
| P3-03-DOC-04 | Immutable artifact | Successful artifact cannot be updated/deleted; replacement points to the previous artifact and does not rewrite it |
| P3-03-DOC-05 | Private access | Same-tenant authorized role downloads; wrong tenant, wrong property, wrong role, expired guest access and guessed UUID are denied without leaking existence/path |
| P3-03-DOC-06 | Idempotency/concurrency | Identical command key and source/template tuple produce one request effect and at most one final artifact under PostgreSQL races |
| P3-03-DOC-07 | Artifact catalog | Confirmation, itinerary, folio statement, payment receipt, refund receipt and acknowledged waiver render from their authoritative snapshots and reject invalid lifecycle states |
| P3-03-DOC-08 | Delivery intent | Email action references the immutable artifact through the existing communication/outbox flow and sends only after generation; provider delivery truth remains P3-04 |
| P3-03-EXP-01 | Export lifecycle | Authorized user requests CSV/XLSX, sees pending/processing/completed/failed state, retries failure and downloads completed artifact |
| P3-03-EXP-02 | Export catalog | Arrivals, departures, occupancy, revenue, payments/deposits/refunds, costs/margin/commissions, dietary and tasks/housekeeping definitions exist with documented columns |
| P3-03-EXP-03 | Filters and timezone | Property-local inclusive date inputs become half-open UTC ranges; artifact records the normalized filter snapshot and property scope |
| P3-03-EXP-04 | Currency integrity | Native amount minor units and ISO currency are exported; totals are grouped by currency and no silent cross-currency sum is produced |
| P3-03-EXP-05 | Spreadsheet security | Values whose first non-control/space character is `=`, `+`, `-`, or `@` are neutralized in CSV and XLSX |
| P3-03-EXP-06 | Export authorization | Query builders explicitly apply tenant, membership-property and report-kind capabilities; requester ownership remains required for download unless an administrator is intentionally allowed |
| P3-03-EXP-07 | Scale and streaming | Large fixture export uses cursor/chunk streaming without loading all rows into memory and reports exact successful/failed/total row counts |
| P3-03-UAT-01 | Client browser proof | Authenticated Chromium generates, waits for, downloads and opens representative PDF, CSV and XLSX artifacts; phone viewport can find reservation documents |

## Work packages

Implement in order. Do not start UI actions before the corresponding service and policy tests pass.

### WP-00 — baseline and dependency gate

Tasks:

- [x] Confirm branch and clean baseline: `git merge-base --is-ancestor 4bbc72f HEAD`.
- [x] Run `make test-api`, `make test-api-postgres`, `make test-client`, `make lint`, `make contract`, and `make doctor` before schema changes.
- [x] Add the three direct dependencies from AD-03 and commit lockfile changes with the implementation.
- [x] Add `config/documents.php`, `DOCUMENT_DISK`, `DOCUMENT_RENDERER`, `DOCUMENT_EXPORT_TTL_DAYS`, job queue/timeouts/backoff, and local font/asset configuration.
- [x] Ensure `.env.example`, Compose/API/worker images and CI contain required PHP extensions and renderer dependencies.

Stop if the baseline gate fails for an unrelated reason. Record the exact failure; do not weaken an existing test to proceed.

### WP-01 — schema, enums and models

Create a single forward migration after `2026_08_18_000300`.

#### `document_generation_requests`

Add:

- tenant UUID primary pattern used by other tenant models;
- nullable `requested_by` user foreign key;
- `document_template_id`, `reservation_id`, `guest_id`, `payment_id`, and `reservation_change_id` where applicable, with tenant-safe foreign keys;
- `kind` and `locale`;
- `status` defaulting to `pending`;
- JSON `source_snapshot` and SHA-256 `source_checksum`;
- unique tenant-scoped `deduplication_key`;
- attempt counter, `started_at`, `completed_at`, `failed_at`, and bounded `last_error`;
- timestamps and indexes for tenant/status/created time and reservation/kind.

Use explicit subject foreign keys instead of an unconstrained morph so property/tenant access remains provable.

#### Extend `generated_documents`

Add:

- unique `document_generation_request_id`;
- nullable `payment_id` and `reservation_change_id` links;
- nullable self-reference `replaces_document_id`;
- `storage_disk`, `file_name`, `mime_type`, `size_bytes`;
- `source_checksum`, `renderer`, `renderer_version`, `template_version`, `locale`;
- `generated_at`, nullable `expires_at`, and nullable `purged_at`.

Keep `storage_path` opaque and keep existing `checksum` as the artifact-byte SHA-256. Existing seeded rows must migrate safely and be labeled `legacy` in renderer/source metadata rather than deleted.

#### Extend `report_exports`

Add:

- nullable `property_id` with tenant-safe relation;
- `format` (`csv` or `xlsx`), `locale`, and normalized filter checksum;
- `storage_disk`, `file_name`, `mime_type`, `size_bytes`, and artifact `checksum`;
- attempts, `started_at`, `failed_at`, bounded `last_error`, `expires_at`, and `purged_at`;
- unique tenant-scoped deduplication/idempotency key and status indexes.

Add enums:

- `DocumentKind`
- `DocumentGenerationStatus`
- `ReportExportKind`
- `ReportExportFormat`
- `ReportExportStatus`

Add model relationships/casts and register `DocumentGenerationRequest` with `TenantAuditObserver`. `GeneratedDocument` remains append-only. `ReportExport` may change only through a transition service; direct Filament editing remains disabled.

Tests:

- [x] SQLite and PostgreSQL migrations pass from empty database and current seeded baseline.
- [x] Tenant composite FKs reject cross-tenant subjects.
- [x] Enum/check constraints reject unknown state/format/kind.
- [x] Deduplication keys race correctly on PostgreSQL.
- [x] Existing generated-document rows survive migration.

Implementation evidence recorded on 2026-08-18:

| Work package | Result | Evidence |
| --- | --- | --- |
| WP-00 | Complete | Pre-change gates passed: SQLite API 245 tests, PostgreSQL API 245 tests, client 6 tests, lint/static analysis, OpenAPI contract and service doctor. The updated `inn-api` image builds successfully and `docker compose config --quiet` passes. |
| WP-01 | Complete | Full SQLite API suite passes 252 tests with 1,768 assertions; full PostgreSQL API suite passes 252 tests with 1,744 assertions. Focused schema, migration compatibility, and document/export PostgreSQL race tests pass. The current database migrated forward without deleting existing rows, and a fresh SQLite migration passes. |
| WP-02 | Complete | `DocumentGenerationFlowTest` covers lifecycle matrices, all six snapshot kinds, canonical determinism, retry immutability, redaction, manual/provider wording, refund totals and acknowledged-waiver immutability. |
| WP-03 | Complete | Encrypted after-commit jobs, overlap locks, parser-open validation, temporary-object promotion/cleanup, failure/retry state, idempotent requests/workers, real Poppler render checks and immutable replacement lineage are executable. |
| WP-04 | Complete | Staff/API/guest controllers authorize before storage, fail closed on integrity/expiry/purge, emit path-free audits, and idempotently queue attachment-by-artifact email intent; role/property denials and guest phone download pass. |
| WP-05 | Complete | Eight tenant/property-scoped definitions generate formula-safe CSV/XLSX with half-open local-date filters, exact row/integrity metadata, retry/expiry/purge, League CSV/OpenSpout parsing and isolated PostgreSQL coverage. |
| WP-06 | Complete | Filament exposes generation, failure/retry, download/email/replacement, payment/refund receipt and report request/retry/purge actions; guest documents remain distinct and phone-safe. |
| WP-07 | Complete | Authenticated command-backed API routes and OpenAPI schemas pass idempotency, safe-resource, lifecycle, authorization and 82-path/116-operation contract verification. |
| WP-08 | Complete | The seven-test Chromium client suite includes the state-changing P3-03 staff/guest journey: queued real PDF plus CSV/XLSX generation, download, parser/open validation and 390×844 guest access. |
| WP-09 | Complete | Main runbook, UAT ledger and Phase 3 status are updated; legacy arbitrary-byte storage and private-path UI exposure are removed; QloApps is recorded as inspiration only. Final gates pass: SQLite 280 tests/1,921 assertions, PostgreSQL 280 tests/1,897 assertions, focused PostgreSQL 32 tests/184 assertions, seven authenticated browser tests, lint/static analysis, OpenAPI 82 paths/116 operations/100 references, Docker build/health, dependency audits and diff hygiene. |

### WP-02 — trusted templates and canonical snapshot builders

Create:

- `App\Contracts\Documents\DocumentRenderer`
- `App\Services\Documents\CanonicalJson`
- `App\Services\Documents\DocumentSnapshotFactory`
- one snapshot builder per `DocumentKind`
- versioned Blade views under `resources/views/documents/`

Rules:

- Snapshot all money as integer minor units plus uppercase ISO currency.
- Snapshot instants in UTC ISO-8601 and include the property's timezone and explicitly formatted property-local dates/times.
- Sort associative keys before hashing. Preserve semantically ordered line/allocation lists with stable database ordering.
- Store template ID/version, locale, application version and source schema version in the snapshot envelope.
- Use escaped Blade output. Template JSON selects approved sections/options; it cannot contain executable PHP, arbitrary Blade, remote URLs or untrusted raw HTML.
- Load logos, fonts and CSS only from approved packaged/private tenant assets.

Required snapshot content:

| Kind | Authoritative source and minimum state |
| --- | --- |
| `reservation_confirmation` | Confirmed-or-later reservation, guest, property, quote/price snapshot, dates, occupants, allocations, cancellation/deposit terms and current balance |
| `itinerary` | Confirmed-or-later reservation plus stable ordered room/activity/service allocations, meeting details and guest-facing notes only |
| `folio_statement` | Reservation, folio status, ordered immutable folio lines/reversals, succeeded payments, completed refunds and balance; label open folio `Interim` and closed folio `Final` |
| `payment_receipt` | One `Payment` in `succeeded`/fully refunded state, origin, method wording, amount/currency, processed time, reference, reservation and payer; never claim online capture for manual origin |
| `refund_receipt` | One `refund_completed` reservation change, source payment, amount/currency, completion reference, reason and remaining paid/refunded totals |
| `waiver_copy` | Exact acknowledged `GuestPortalDocument` version/hash, acknowledgement actor facts and timestamp, guest and reservation; reject missing acknowledgement |

Tests:

- [x] Data providers cover every valid/invalid lifecycle state.
- [x] Equivalent snapshots produce identical canonical JSON/source checksum.
- [x] Changes after request creation do not change a retry's snapshot.
- [x] Sensitive internal notes, evidence storage paths and secrets never enter snapshots.
- [x] Manual versus provider-origin wording remains truthful.

### WP-03 — request commands and generation state machine

Create:

- `RequestDocumentGeneration`
- `RetryDocumentGeneration`
- `GenerateDocument` queued job
- `SpatieDocumentRenderer`
- `DocumentArtifactStore`

Request command:

1. Authorize the document kind against the subject and property scope.
2. Lock/reload authoritative subject rows needed to build a consistent snapshot.
3. Select the active template version for kind/locale, with documented locale fallback.
4. Build and store snapshot/checksum inside the transaction.
5. Claim the tenant deduplication key and return the existing request for exact replay.
6. Record outbox/audit event and dispatch `GenerateDocument` after commit.

Generation job:

1. Acquire the overlap lock and lock the request row.
2. Treat `generated` as success replay; reject non-retryable state.
3. Transition to `processing` and increment attempts.
4. Render only the stored snapshot/template version.
5. Validate non-empty PDF bytes, `%PDF-` header and parser-open success before storage.
6. Write to an opaque temporary object, compute size/MIME/checksum, then promote to final object.
7. In one transaction, create the immutable `GeneratedDocument`, transition the request to `generated`, and record audit/outbox state.
8. On failure, remove safe temporary objects, store a redacted bounded error, mark `failed`, and let configured retry policy decide the next attempt.

Retry command reuses the original snapshot. A replacement request is a new request with a new current snapshot/template and its successful document links `replaces_document_id` to the prior artifact.

Tests:

- [x] Dispatch occurs after commit and not after rollback.
- [x] Queue payload is encrypted and contains no guest snapshot/address/document number.
- [x] Duplicate HTTP/Filament requests replay one request.
- [x] Concurrent workers create at most one final artifact.
- [x] Crash before/after object write does not leave a completed row pointing to a missing object.
- [x] Renderer failure, missing template/font, invalid bytes and storage failure are retryable and visible.
- [x] Replacement never mutates prior artifact.

### WP-04 — authorized access, guest access and delivery intent

Create:

- `GeneratedDocumentDownloadController`
- `GuestGeneratedDocumentDownloadController`
- kind-aware `GeneratedDocumentPolicy`
- `QueueGeneratedDocumentEmail`

Policy rules:

- Confirmation, itinerary and waiver: reservation-managing staff in property scope; active guest token for its own reservation.
- Folio, payment and refund artifacts: staff with `canViewGuestMoney()` in property scope; active guest token for its own reservation where guest visibility is enabled.
- Generate/replace financial artifacts: `canManageGuestMoney()`; refund completion remains Finance-authorized through the existing command.
- Cross-tenant/property UUIDs return the existing not-found/forbidden convention without path or metadata leakage.
- Expired or purged artifacts cannot download, but their audit row remains visible to authorized staff.

Downloads:

- authorize before disk access;
- verify object existence and optionally checksum before response;
- set safe content type/disposition/filename and `X-Content-Type-Options: nosniff`;
- do not cache guest/financial documents publicly;
- append a download audit record without logging document contents/path; and
- handle missing object as an operational failure, not as a successful empty download.

Email intent:

- accept an existing generated artifact only;
- create/reuse an idempotent `Communication` with artifact ID in metadata, never a path;
- attach from the configured disk in the existing local delivery flow;
- record who queued it and recipient; and
- leave accepted/delivered/bounce/complaint provider truth to P3-04.

Tests:

- [x] Role matrix and property/tenant denial for metadata, download, retry, replace and email.
- [x] Guest active/expired/replayed token coverage.
- [x] Missing, expired, purged and checksum-mismatched objects fail closed.
- [x] Email retry does not create duplicate communication/artifact effects.

### WP-05 — report definition and export pipeline

Create:

- `App\Contracts\Reports\ReportDefinition`
- `ReportDefinitionRegistry`
- one definition/query/row mapper per report kind
- `RequestReportExport`
- `RetryReportExport`
- `GenerateReportExport`
- `CsvReportWriter`
- `XlsxReportWriter`
- `ReportArtifactStore`

All definitions expose:

- kind and required capability;
- allowed filters and validation rules;
- property-local date normalization to half-open UTC range;
- stable column keys, localized labels and types;
- an explicitly tenant/property-scoped query;
- stable ordering and chunk/cursor strategy; and
- a row mapper that neutralizes spreadsheet formulas.

Required catalog:

| Kind | Required minimum columns/invariants |
| --- | --- |
| Arrivals | Property, confirmation, primary guest, local arrival, nights, occupants, assigned resource/category, status, deposit/balance and arrival notes safe for operations |
| Departures | Property, confirmation, guest, local departure, assigned resource, folio status/balance, housekeeping state |
| Occupancy | Property-local date, sellable capacity, blocked capacity, occupied units/capacity, arrivals, departures, occupancy percentage and denominator definition |
| Revenue | Reservation/reference, stay dates, native currency, booked/refunded/net minor amounts; totals grouped by currency and optional audited conversion metadata only |
| Payments/deposits/refunds | Payment origin/method/status/reference, deposit due/paid status, completed/open refund facts, native currency and immutable IDs |
| Costs/margin/commissions | Source reference, occurrence date, category/payee, native amount/currency, commission status; no silent conversion |
| Dietary | Stay date, guest-safe identifier/name for authorized operations, dietary/allergy facts, meal/service occurrence and notes; exclude identity document/payment data |
| Tasks/housekeeping | Property, resource/reservation, task/category, assignee, due/completed local time, status and housekeeping state |

Export behavior:

- Validate kind/format/filter/capability at request time and again in the worker.
- Store normalized filters and their checksum before dispatch.
- CSV includes UTF-8 BOM only if acceptance proves required; otherwise emit standards-compliant UTF-8 with explicit content type.
- XLSX uses OpenSpout streaming and typed cells where safe.
- Neutralize formula-leading values in every user-controlled text cell for both formats.
- Store exact row count, size, checksum, requester, scope and expiry.
- Default export artifact expiry is configurable and initially seven days. Expiry/purge removes only the object and marks `purged_at`; it never deletes the ledger/audit row.

Tests:

- [x] Every definition has filter/column golden tests.
- [x] Cross-tenant/property fixtures never appear even when IDs/filters are manipulated.
- [x] Currency totals and half-open date boundaries are correct.
- [x] Formula payloads beginning after spaces/control characters are neutralized in parsed CSV and XLSX cells.
- [x] Empty, one-row and large chunked exports have exact counts.
- [x] CSV parses with League CSV; XLSX opens with OpenSpout/ZIP and has expected sheets/cells.
- [x] Failure/retry/expiry/purge and concurrent identical request behavior pass on PostgreSQL.

### WP-06 — Filament and guest-portal product surfaces

Generated documents:

- Move navigation from configuration-only semantics to the appropriate operations/finance surfaces.
- Show kind, subject, request status, template version, locale, generated time, size, checksum abbreviation, replacement, expiry and requester.
- Remove raw `storage_path` from all infolists/relation managers.
- Add authorized actions: generate, retry failed request, download, email, replace/regenerate and view audit.
- Add reservation relation-manager actions with only lifecycle-valid document kinds.
- Add payment receipt action to reconciled payments and refund receipt action to completed refund changes.

Report exports:

- Add header action with kind, format, property/date filters and kind-specific options.
- Show progress, row count, requester, expiry and redacted failure.
- Add download, retry and purge-expired-object actions with policies.
- Keep finance report types finance-scoped; operations reports must not expose finance columns.

Guest portal:

- Add a `Generated stay documents` section to the existing documents/folio experience.
- List only generated, non-expired, guest-visible artifacts for the active reservation.
- Keep acknowledgment source documents distinct from generated PDF copies.
- Validate phone viewport and accessible labels/loading/failure states.

Filament tests must call real actions/services and assert database/object effects. Page rendering alone is insufficient.

### WP-07 — API and OpenAPI contract

Add authenticated tenant routes backed by the same commands/policies as Filament:

```text
POST /api/v1/reservations/{reservation}/document-requests
GET  /api/v1/document-requests/{documentGenerationRequest}
POST /api/v1/document-requests/{documentGenerationRequest}/retry
GET  /api/v1/generated-documents/{generatedDocument}
GET  /api/v1/generated-documents/{generatedDocument}/download
POST /api/v1/generated-documents/{generatedDocument}/email

POST /api/v1/report-exports
GET  /api/v1/report-exports/{reportExport}
POST /api/v1/report-exports/{reportExport}/retry
GET  /api/v1/report-exports/{reportExport}/download
```

Rules:

- Create/retry/email endpoints use the existing idempotency middleware.
- Responses expose state, safe filename, checksum, size, timestamps and related IDs; never disk/path.
- Pending download returns a clear conflict/state response; missing/expired/purged returns the agreed problem shape.
- Update `contracts/openapi.yaml` and contract fixtures in the same work package.
- Guest downloads remain guest-session web/API routes and must not expose staff endpoints.

Tests cover validation, exact replay status/body, policies, tenant/property isolation and every lifecycle response.

### WP-08 — real artifact and browser acceptance

Add a deterministic P3-03 UAT fixture/seeder that references the existing closed-loop reservation rather than deleting/recreating financial history.

Authenticated Chromium journey:

1. Sales/Manager opens the completed UAT reservation and requests confirmation/itinerary.
2. Worker processes the request; UI progresses to generated without manual database changes.
3. Browser downloads the PDF; test verifies filename, MIME, `%PDF-`, parseable text and non-blank rendered page.
4. Operations downloads a permitted itinerary but is denied a finance-only export.
5. Finance generates payment and refund receipts from existing reconciled/completed records.
6. Finance requests filtered payments/refunds CSV and revenue XLSX, waits for completion, downloads and parses them.
7. Viewer/cross-property context cannot access artifact metadata or bytes.
8. Guest phone viewport sees and downloads its allowed confirmation/folio artifact; expired guest access is denied.
9. A forced renderer/export failure is visible and successfully retried from Filament without duplicate final artifacts.

Real-render integration assertions:

- use Poppler `pdfinfo`/`pdftotext` or an equivalent parser in CI;
- render at least the first page to PNG and assert dimensions/non-blank content;
- validate long guest names, accented Spanish text, multi-page folio, zero/partial/overpayment/refund values and page-break/footer behavior;
- open XLSX through OpenSpout and CSV through League CSV; and
- confirm no storage path, guest token, evidence path or secret appears in UI/API/log assertions.

### WP-09 — documentation, cleanup and PR gate

- [x] Update [Client UAT ledger](client-uat-ledger.md) with requirement IDs and executable evidence.
- [x] Update [Phase 3 plan](client-ready-phase-3-plan.md) to `implemented and verified` only after all gates pass.
- [x] Document environment variables, worker queue and local artifact inspection in the main setup/runbook.
- [x] Add a renderer/export failure troubleshooting note without exposing sensitive snapshots.
- [x] Remove or migrate the legacy caller-supplied-byte `DocumentService::store()` path; no production call site may remain.
- [x] Run dependency/license audit and record QloApps as inspiration only—no copied OSL source.
- [x] Preserve unrelated worktree changes and synthetic audit history.

## File-level implementation map

Paths are prescriptive by responsibility; agents may split a class when tests show a clearer boundary, but must not merge domain, rendering, storage and UI concerns into one service.

| Area | Create/modify |
| --- | --- |
| Dependencies/config | `apps/api/composer.json`, `apps/api/composer.lock`, `apps/api/config/documents.php`, env examples, Docker/CI renderer requirements |
| Schema | new P3-03 migration after `2026_08_18_000300`, models and enum files under `app/Models` and `app/Enums` |
| Snapshots | `app/Services/Documents/*SnapshotBuilder.php`, `CanonicalJson.php`, `DocumentSnapshotFactory.php` |
| Rendering | `app/Contracts/Documents/DocumentRenderer.php`, `SpatieDocumentRenderer.php`, `DocumentArtifactStore.php`, `app/Jobs/GenerateDocument.php` |
| Templates | `resources/views/documents/layout.blade.php`, per-kind Blade templates, packaged CSS/fonts/assets |
| Commands | `RequestDocumentGeneration.php`, `RetryDocumentGeneration.php`, `QueueGeneratedDocumentEmail.php` |
| Access | document controllers, policies, routes and safe API resources/requests |
| Reports | `app/Contracts/Reports`, `app/Services/Reports`, `app/Jobs/GenerateReportExport.php`, CSV/XLSX writers |
| Filament | generated-document and report-export resources, reservation/payment/refund actions and relation managers |
| Guest | existing guest portal controller/service/views plus generated-document download route |
| Contract | `contracts/openapi.yaml`, route verification fixtures/tests |
| Tests | focused feature tests for documents/exports, PostgreSQL concurrency, policy/API tests, Filament action tests and Playwright UAT |

## Failure and edge-case matrix

| Boundary | Required cases |
| --- | --- |
| Request | duplicate key same payload, duplicate key different payload, inactive/missing template, unsupported locale, stale subject, invalid lifecycle, transaction rollback |
| Snapshot | unordered input, long/unicode values, null optional fields, multiple currencies, negative/refund lines, reversed folio lines, internal-field redaction |
| Queue | duplicate workers, kill before render, kill after object write, retry exhaustion, stale cache lock, encrypted payload, dispatch rollback |
| Renderer | invalid HTML/template schema, missing local asset/font, oversized/multi-page content, malformed/non-PDF bytes, timeout, out-of-memory-safe failure |
| Storage | write failure, partial/temp object, promotion failure, missing object, checksum mismatch, wrong MIME, expired/purged object, disk unavailable |
| Access | cross-tenant UUID, cross-property membership, wrong role, revoked staff, expired guest link, guessed ID, request/artifact mismatch |
| Email | pending/failed document, duplicate send command, missing object, suppressed recipient, attachment reference without path leakage |
| Export | empty/large set, date cutoff, DST property day, manipulated property filter, mixed currencies, formula injection, writer failure, bad row, expiry during download |
| UI | polling refresh, back/duplicate click, mobile layout, failure/retry visibility, download interrupted, accessible action labels |

## Verification commands and required evidence

Run from repository root unless stated otherwise:

```bash
git diff --check
make test-api
make test-api-postgres
make test-client
make test-web
make lint
make contract
make build-api
make doctor
cd apps/api && composer audit
cd apps/web && npm audit --audit-level=high
```

Add a dedicated real-artifact gate if keeping it inside the full suite makes failures hard to diagnose, for example `make test-documents-exports`. It must use the isolated `inn_test` PostgreSQL database and a temporary/fake artifact disk; it must never refresh the local demo database.

The PR description must report:

- exact test/assertion counts for fast and PostgreSQL suites;
- real PDF parser/render result and tested document kinds;
- CSV/XLSX parsed row/cell results and formula-injection fixtures;
- authenticated and guest browser journey counts/viewports;
- tenant/property/role denial evidence;
- retry/concurrency/idempotency evidence;
- dependency and license audit result; and
- any synthetic records/artifacts retained or purged through normal lifecycle behavior.

## Definition of done

P3-03 is complete only when all requirement rows have executable proof and the following are simultaneously true:

1. No production path accepts arbitrary bytes and labels them as a generated PDF.
2. Every artifact originates from an immutable canonical source snapshot and versioned trusted template.
3. Real PDF/CSV/XLSX files are generated asynchronously, stored privately, integrity-recorded and downloadable only after authorization.
4. Duplicate requests/workers cannot create duplicate business effects or conflicting final artifacts.
5. Document and export failures are visible, redacted, retryable and audited.
6. Financial terminology remains truthful and non-fiscal until the client supplies legal requirements.
7. The state-changing browser journey downloads and opens representative artifacts across staff and guest surfaces.
8. Existing P3-01/P3-02 journeys, PostgreSQL concurrency gates, static analysis, OpenAPI and Docker health remain green.
9. `main` is not advanced and P3-04 is not created until this branch is reviewed and merged.

## Primary references

- [Laravel 13 queues](https://laravel.com/docs/13.x/queues): after-commit dispatch, encrypted jobs, uniqueness, overlap locks, retries and failure behavior.
- [Laravel 13 filesystem](https://laravel.com/docs/13.x/filesystem): private disks, downloads and temporary URLs.
- [Laravel 13 mail](https://laravel.com/docs/13.x/mail): queued mail and attachments from storage.
- [Filament 5 exports](https://filamentphp.com/docs/5.x/actions/export): queue batches, CSV/XLSX, authorization limits, per-record scoping and formula-injection warning.
- [Spatie Laravel PDF v2 installation/drivers](https://spatie.be/docs/laravel-pdf/v2/installation-setup), [queued generation](https://spatie.be/docs/laravel-pdf/v2/basic-usage/queued-pdf-generation), and [testing](https://spatie.be/docs/laravel-pdf/v2/basic-usage/testing-pdfs).
- [QloApps payment receipt](https://github.com/Qloapps/QloApps/blob/develop/classes/pdf/HTMLTemplatePaymentReceipt.php), [booking-voucher access](https://github.com/Qloapps/QloApps/blob/develop/controllers/front/PdfBookingVoucherController.php), and [receipt numbering](https://github.com/Qloapps/QloApps/blob/develop/classes/order/OrderPaymentDetail.php): domain inspiration only under OSL-3.0.
- [AureusERP PDF helper](https://github.com/aureuserp/aureuserp/blob/master/plugins/webkul/support/src/Traits/PDFHandler.php), [print/send action](https://github.com/aureuserp/aureuserp/blob/master/plugins/webkul/accounts/src/Filament/Resources/InvoiceResource/Actions/PrintAndSendAction.php), and [invoice exporter](https://github.com/aureuserp/aureuserp/blob/master/plugins/webkul/accounts/src/Filament/Exports/InvoiceExporter.php): MIT-licensed UI/state reference, not Inn's storage/security implementation.
