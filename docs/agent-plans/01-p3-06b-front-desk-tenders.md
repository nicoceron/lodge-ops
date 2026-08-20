# Agent 01 — P3-06B front-desk tenders

## Copy/paste assignment

> Implement P3-06B from synchronized `origin/main` only after P3-06A is merged. Read this file, the coordinator README, the entire existing P3-06B plan, P3-06A plan/runbook, P3-03 document plan, and all current payment/refund/folio policies and services. Deliver truthful cash, bank transfer, and standalone external-terminal recording with receipts, cash shifts, controlled refunds, private evidence, duplicate review, and exact-once accounting. This is not integrated terminal control. Do not start Point/QR or broaden card-data scope.

## Branch and ownership

- Branch: `codex/p3-06b-front-desk-tenders` from synchronized `main` after Agent 00.
- Own payment classification/backfill, manual tender commands, tender details/evidence, cash shifts/movements, API/Filament/OpenAPI/tests/runbook.
- Make only the minimal typed refactor to shared `PaymentService`; preserve every P3-06A test.
- Do not change Mercado Pago webhook/gateway behavior.
- Read first: `docs/p3-06b-front-desk-tenders-implementation-plan.md`, payment migrations/models/services/controllers/resources/policies, document/receipt services, and N2 refund/change services.

## Persistence and migration truth

- Make `payments.channel` canonical. Keep legacy `method` temporarily with constraints preventing contradictory combinations.
- Deterministic backfill uses legacy `method` **plus** `origin` and provider/account identity:
  - `bank_transfer` → `bank_transfer / staff_recorded / manual`
  - `cash` → `cash / staff_recorded / manual`
  - `card` → `external_terminal / staff_recorded / manual`
  - `other` → `manual_other / staff_recorded / manual`
  - `mercado_pago_checkout_pro` → `online_checkout / provider_reported / provider`
- Known Checkout Pro/provider-origin rows map to `online_checkout / provider_reported / provider` even if their legacy method is `card`. A `card` row with provider origin or any contradictory/unknown combination aborts into a reported exception list unless its provider identity proves the mapping. Preserve `manual_other`; never guess or discard rows.
- Add `payment_tender_details`: tenant/property/payment/reservation, normalized processor/merchant aliases, terminal ID, transaction/authorization reference, batch reference, nullable brand, nullable exactly-four numeric last digits, command key/checksum, duplicate-review state.
- Use existing `GuestPaymentEvidence`/`guest_payment_evidence` as the canonical payment-evidence aggregate. Extend and migrate it in place for nullable payment/refund/tender linkage, disk/key, safe original name, detected MIME, size, SHA-256, scan state, actor/timestamps; preserve IDs, reservation/guest links and review history. Do **not** create a second competing `payment_evidence_artifacts` truth. Agent 12 later migrates bytes/storage metadata without replacing this aggregate.
- Add `cash_shifts` and append-only `cash_shift_movements`, including `reverses_movement_id` for signed opposing corrections.
- PostgreSQL partial unique index: one open shift per cashier/property/currency. Add portable SQLite validation.
- Unique normalized external-terminal transaction identity includes non-null tenant, property, merchant/account alias, processor, terminal identifier and reference. Enforce it with canonical non-null columns or a PostgreSQL expression/partial index; do not rely on a nullable multi-column `UNIQUE` that permits duplicate `NULL` identities.

## Commands and rules

- Implement typed `RecordFrontDeskPayment`, `OpenCashShift`, `RecordCashMovement`, `CloseCashShift`, `ApproveCashVariance`, `RequestManualExternalRefund`, `CompleteManualExternalRefund`, `ResolveTenderDuplicate`, and the missing remaining-reversible-amount correction command.
- Every command above persists an idempotency key and canonical request checksum at the application layer: same key/body replays the original outcome; same key/different body fails even outside HTTP middleware.
- Replace the generic manual input path that accepts arbitrary provider/reference/metadata/captured claims. Server derives origin, capture truth, currency, allocation and folio effect.
- Capabilities:
  - record tender: Admin, Manager, Operations, Finance;
  - operate own cash shift: Admin, Manager, Operations;
  - resolve duplicate/variance/refund exception: Admin, Manager, Finance.
- Record an explicit allow/deny decision and tests for **each** Sales payment action. Do not omit Sales from the matrix or broaden it by accident; default deny is acceptable only when documented against RG-4.11.
- A **posted** external-terminal payment requires processor, merchant/account alias, terminal identifier and transaction/authorization reference. Missing identity creates a non-posted Finance exception/draft with no payment, deposit or folio effect; resolving it reruns the typed command and duplicate check.
- Expected cash equals opening float + succeeded cash payments − completed cash refunds + pay-ins − pay-outs + signed opposing corrections.
- A cash refund cannot be marked dispensed without an authorized open shift and matching negative movement.
- Use reservation/payment/deposit locks before shift locks when both domains are touched. Shift-only commands lock the shift first.
- Commit payment, tender detail, folio/deposit mutation, cash movement, audit, and document-generation/outbox intent atomically; generate PDFs idempotently after commit.
- Split staff/finance API resources from guest/public projections. Hide provider metadata, notes, private paths, and evidence details unless policy allows them.
- Prefer structured allowlisted tender fields and length/character constraints. Apply targeted PAN/Luhn detection as defense in depth with a documented false-positive resolution path for legitimate authorization/batch references. Store at most nullable brand and last four; never CVV/expiry/track/PIN, and never claim the detector proves PCI compliance.
- Evidence uses allowlisted size/MIME/content, safe filename/`Content-Disposition`, quarantine until scanner success and no pending/rejected download. Test MIME spoof, polyglot/malware, malformed file and scanner outage.

## Required UI and journeys

- Reservation hub actions for cash, bank transfer, standalone terminal and manual-other with channel-specific fields and confirmation copy.
- Cashier open/close shift, current expected cash, pay-in/pay-out, immutable correction, variance reason, approval, and shift report.
- Finance duplicate/exception/refund/evidence queues with authorized private downloads.
- Receipts accurately say “recorded external terminal payment” and never “Inn charged card.”
- Real browser flow: open shift → record cash deposit → issue receipt → record bank transfer and verify its classification → upload/review private evidence → record standalone terminal balance → duplicate blocked/reviewed → request/complete a truthful manual external-terminal refund → check out → cash refund with movement → close exact/variance shift → Finance approval → zero ledger imbalance.

## Tests and completion

- Migration/backfill/rollback and PostgreSQL constraints, including provider-origin legacy `card`, contradictory origin/method/provider rows and preservation of existing evidence IDs/history.
- Zero/negative/partial/exact/over-balance, currency mismatch, closed reservation/folio, canceled/no-show, open/completed refund, and remaining reversible amount.
- Same external reference on same/different reservation, terminal, merchant/account; same key/different body.
- Races: record twice, two shift opens, payment versus close, refund versus close, correction versus reversal.
- Property-local business-date coverage: before/at/after midnight, overnight shifts, DST spring/fall transitions, cashier time-zone display and immutable UTC audit instants.
- Disabled cashier, abandoned/stale shift, authorized forced close, pending variance, crash after commit before response and outbox/document retry.
- Role/property/IDOR, private downloads, metadata redaction, log/queue/audit/receipt PAN scan.
- Filament action form/visibility/authorization tests and OpenAPI contract.
- Run universal gates and the state-changing browser journey on PostgreSQL.
- Completion claim is limited to cash, bank-transfer and standalone external-terminal **recording**. Do not claim authorization/capture, terminal integration, processor settlement accuracy, PCI compliance, or SAQ qualification.

## Primary references

- [Existing P3-06B plan](../p3-06b-front-desk-tenders-implementation-plan.md)
- [PCI SSC SAQ selection](https://listings.pcisecuritystandards.org/pci_security/completing_self_assessment)
- [PCI SSC SAQ B-IP guidance](https://www.pcisecuritystandards.org/faqs/1313/)
- [PCI SSC PAN truncation](https://www.pcisecuritystandards.org/faqs/1091/)
- [PCI SSC sensitive authentication data rule](https://www.pcisecuritystandards.org/faqs/1533/)
- [Laravel validation](https://laravel.com/docs/13.x/validation), [filesystem](https://laravel.com/docs/13.x/filesystem), and [database transactions](https://laravel.com/docs/13.x/database)
