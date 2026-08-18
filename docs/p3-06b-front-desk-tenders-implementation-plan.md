# P3-06B front-desk tenders implementation plan

Date: 2026-08-18  
Status: **planned follow-on; do not start before P3-06A merges**  
Branch: `codex/p3-06b-front-desk-tenders`  
Base: synchronized `main` containing the merged [P3-06A online payment slice](p3-06-mercado-pago-payments-implementation-plan.md)  
Follow-on: [P3-06C Mercado Pago Point and QR](p3-06c-mercado-pago-point-qr-implementation-plan.md)

## 1. Outcome and boundary

P3-06B makes the lodge operationally complete for payments accepted outside Inn while avoiding premature terminal integration. Front-desk staff can record and receipt:

- cash received at the property;
- an approved card-present payment taken on a standalone external terminal;
- the existing bank-transfer evidence/reconciliation workflow;
- a manual refund executed outside Inn with Finance evidence and approval.

The payment remains an Inn payment and folio effect, but `origin = manual` truthfully states that Inn did not authorize or capture it. A standalone-terminal payment must never be displayed as an online or integrated provider transaction.

This is the fastest hardware-independent release: the client may choose Payway, Getnet, Mercado Pago Point, Mobbex or another terminal contract without waiting for an API integration.

Primary references:

- [Mercado Pago Point standalone and integrated terminal options](https://www.mercadopago.com.ar/developers/es/docs/mp-point/overview)
- [Mobbex Smart POS standalone and integrated modes](https://www.mobbex.com/smart-pos/)
- [PCI SSC guidance for small merchants](https://www.pcisecuritystandards.org/merchants/)
- [Laravel database transactions](https://laravel.com/docs/13.x/database#database-transactions)
- [Laravel authorization](https://laravel.com/docs/13.x/authorization)

### Included

1. Explicit payment channels and entry modes across existing/manual and future/provider payments.
2. Standalone external-terminal recording with processor, terminal, authorization/reference, batch, brand and last four digits only.
3. Minimal cash-shift open/close and variance controls sufficient to explain cash received against Inn payments.
4. Existing bank-transfer evidence retained as a first-class channel, not rebuilt.
5. Receipt generation through P3-03 for cash and external-terminal payments.
6. Append-only corrections/refunds with Finance approval and evidence.
7. Reservation hub, Payments workspace, Finance exception queue, API/OpenAPI, exports and state-changing UAT.

### Excluded

- Sending amounts to a physical terminal, receiving terminal webhooks or controlling device state; those are P3-06C.
- Raw card number, expiry, CVV, magnetic-stripe, chip or NFC data.
- Keyed/manual card-number entry or a virtual terminal inside Inn.
- Card-on-file, deposits authorized for later capture, recurring/off-session charging or token vaulting.
- Full restaurant inventory, table service or general retail POS.
- Accounting/general-ledger sync and fiscal invoice issuance.
- Provider fee claims when the external terminal statement has not been imported or entered by Finance.

## 2. Non-negotiable invariants

| ID | Invariant |
| --- | --- |
| TENDER-01 | Every payment has an explicit `channel` and `origin`; `external_terminal` with `origin = manual` can never be presented as provider-authorized by Inn. |
| TENDER-02 | Staff-entered money is calculated against the authoritative reservation/deposit/folio balance. Client input cannot select a different tenant, property, currency or resulting folio effect. |
| TENDER-03 | Card-present records contain at most processor, terminal reference, authorization/reference, batch, brand and last four digits. PAN, expiry, CVV, track, chip and NFC payloads are rejected and never logged. |
| TENDER-04 | A `(tenant, processor, merchant account, terminal, provider reference)` identity can create at most one payment. Blank references require an explicit Finance exception and cannot silently bypass duplicate checks. |
| TENDER-05 | A manual payment is immutable after posting. Corrections use the existing reversal/refund lifecycle with reason, actor and evidence. |
| TENDER-06 | Only an open cash shift owned by the current property may receive a cash payment. Shift close records expected, counted and variance values without rewriting its payments. |
| TENDER-07 | Cash expected amount is derived from append-only shift movements and posted payments/refunds; staff cannot submit it as an authoritative value. |
| TENDER-08 | Closing with non-zero variance requires a reason; variance above the configured threshold requires Finance approval. |
| TENDER-09 | Retrying a record/refund/shift-close command with the same idempotency key returns the original result and creates no duplicate payment, folio line, movement or receipt. |
| TENDER-10 | A manual external refund is not complete until Finance records execution reference/evidence. It never calls or claims success from a provider API. |
| TENDER-11 | Payment, deposit, folio, refund, shift and document effects commit together or roll back together. Notifications/documents dispatch only after commit. |
| TENDER-12 | Sales/front-desk may record allowed tenders; Finance alone resolves duplicates, approves material variance and completes external refunds. Viewer is read-only and property scoped. |

## 3. Domain and persistence changes

Prefer extending the existing `payments` authority rather than creating a parallel tender/payment aggregate.

### Payment channel and entry mode

Add enum-backed fields to `payments`:

- `channel`: `bank_transfer`, `cash`, `external_terminal`, `online_checkout`, `integrated_terminal`, `qr`;
- `entry_mode`: `staff_recorded`, `provider_reported`;
- preserve `origin = manual|provider` and add PostgreSQL checks tying valid combinations together;
- backfill existing evidence/manual card rows deterministically and prove the backfill in migration tests;
- provider channels remain unused until their corresponding P3-06A/P3-06C command creates them.

### `payment_tender_details`

One optional tenant-scoped row per payment:

- payment, reservation, property and channel copies guarded by foreign keys/checks;
- processor/acquirer and merchant-account alias;
- terminal identifier, authorization/reference and batch reference;
- card brand and last four digits with strict format validation;
- received-at property-local instant plus stored UTC instant;
- evidence/document linkage and bounded staff note;
- duplicate-detection status and Finance exception reason;
- unique payment ID and conditional unique external transaction identity.

Do not use a free-form JSON dump from a terminal receipt.

### `cash_shifts`

- tenant, property, cashier, opened/closed/approved actors;
- state: `open`, `closing`, `closed`, `variance_review`;
- opening float, expected cash, counted cash and variance in integer minor units/currency;
- opened/closed/approved UTC instants with property-local display dates;
- close idempotency key, reason and immutable calculation checksum;
- at most one open shift per cashier/property/currency unless a documented operational rule requires otherwise.

### `cash_shift_movements`

- cash shift and optional payment/refund/change reference;
- type: `opening_float`, `payment`, `refund`, `pay_in`, `pay_out`, `adjustment`;
- signed integer minor amount, currency, actor, reason and occurred-at instant;
- deterministic idempotency key and immutable source identity;
- movement rows are append-only; corrections use an opposing movement with linkage.

### Existing records

- `PaymentService::recordManual()` remains the only generic manual-payment application command but accepts a typed tender DTO, not arbitrary metadata.
- Existing bank-transfer evidence approval continues to call it with `bank_transfer/staff_recorded`.
- Existing provider-only application from P3-06A calls a separate method and cannot populate manual tender details.
- Existing reversal/refund and P3-03 document models remain authoritative.

## 4. Commands and lock order

Use the established reservation-first financial lock order. Document any necessary deviation in code and a PostgreSQL race test.

1. `OpenCashShift`
   - authorize property/front-desk access;
   - reject conflicting open shift/currency;
   - create shift plus opening-float movement exactly once.
2. `RecordFrontDeskPayment`
   - lock reservation, selected deposit/folio and current cash shift when applicable;
   - calculate remaining payable amount and validate currency;
   - validate the typed tender fields and scan text inputs for prohibited card-data patterns;
   - create one manual-origin Payment, tender detail, folio effect, optional deposit reconciliation and cash movement;
   - queue P3-03 receipt after commit.
3. `RecordCashPayInOrOut`
   - Finance/front-desk permission according to configured threshold;
   - require typed reason and append a movement; never mutate expected/count totals directly.
4. `CloseCashShift`
   - lock shift and movements;
   - derive expected total, validate counted amount, compute variance and calculation checksum;
   - close within threshold or enter `variance_review`; retry returns the same close result.
5. `ApproveCashVariance`
   - Finance only, different actor when separation-of-duties configuration requires it;
   - approve/reject with reason without changing recorded payments.
6. `RequestManualExternalRefund`
   - reuse `RequestRefund`; validate remaining refundable amount and external execution requirement.
7. `CompleteManualExternalRefund`
   - Finance supplies execution reference/date and private evidence;
   - invoke `CompleteRefund` exactly once and append cash movement when refund tender is cash;
   - reject provider-origin payments, which must use P3-06A/P3-06C provider execution.
8. `FlagOrResolveTenderDuplicate`
   - Finance-only audited exception workflow; resolution never deletes either record.

## 5. API, Filament and reservation UX

### Reservation hub

- `Record front-desk payment` action displays the authoritative due amount and selected allocation.
- Tender selector dynamically requests only valid fields.
- Card instructions explicitly say: process the card on the external terminal first, then record the approved reference; never type the card number into Inn.
- Cash requires the staff member's open shift.
- Success shows one receipt and refreshed deposit/folio projections.

### Payments/Finance workspace

- filters and badges for channel, origin, entry mode, processor, shift, duplicate status and unreconciled external references;
- tender detail and evidence are property/role authorized;
- provider-origin payments hide manual reverse/reconcile actions;
- external-terminal/cash payments expose only their guarded correction/refund actions;
- cash-shift pages show movements, expected/count/variance, review status and receipts without editable historical totals.

### HTTP/OpenAPI

Add narrow endpoints/resources for:

- recording a front-desk payment under a reservation;
- open/show/close shift and append pay-in/pay-out;
- Finance variance approval;
- request/complete manual external refund;
- duplicate/external-reference exception resolution.

Every mutation is authenticated, tenant/property scoped and idempotent. OpenAPI examples use synthetic last-four/reference values and contain no full card data.

## 6. Work packages

| WP | Deliverable | Completion proof |
| --- | --- | --- |
| WP-01 | Channel/entry-mode enums, backfill and constraints | Existing manual/provider rows migrate deterministically; invalid combinations fail on SQLite validation and PostgreSQL constraints |
| WP-02 | Tender-detail and cash-shift schema/models/policies | Cross-property and invalid card-data/duplicate identities denied; one-open-shift race proven in PostgreSQL |
| WP-03 | Typed manual-payment command | Cash/external-terminal/bank-transfer produce one Payment, folio effect, optional deposit application and receipt under replay/concurrency |
| WP-04 | Cash open/movement/close/variance commands | Expected cash is derived, close is exact-once and threshold review is Finance controlled |
| WP-05 | Manual external refunds/corrections | Append-only request/execution/evidence flow; provider-origin misuse denied; cash movement and refund receipt exact-once |
| WP-06 | Filament and reservation hub UX | Sales/Finance allow paths and Viewer/cross-property deny paths pass; prohibited card-field guidance is visible |
| WP-07 | API/OpenAPI/exports/documents | Contract tests, payment report columns and P3-03 receipts reflect truthful channel/origin/entry mode |
| WP-08 | State-changing browser UAT | Standalone card plus cash-shift journey passes with duplicate/replay/variance/refund assertions |

## 7. Ordered agent checklist

1. Rebase only by starting a fresh branch from synchronized `main` after P3-06A merges; never stack on the unmerged P3-06A branch.
2. Run existing payment/evidence/refund/document tests and PostgreSQL suite; record baseline evidence.
3. Add enums, migrations, backfill and database constraints with rollback tests.
4. Add models/factories/relationships/policies and role/property isolation tests.
5. Refactor `PaymentService::recordManual()` behind a typed tender DTO while keeping existing evidence tests green.
6. Implement cash commands and real PostgreSQL open/close/payment races.
7. Implement manual external refund/evidence and collision tests.
8. Add Form Requests, Resources, controllers, OpenAPI and API replay tests.
9. Add Filament actions/resources and reservation-hub projections.
10. Update reports/documents and run byte/parser integrity tests for receipts/exports.
11. Add authenticated Playwright journey and run the full release gates.
12. Update the phase plan, feature matrix and UAT ledger with exact counts and evidence; do not claim integrated terminal processing.

## 8. Required test matrix

### Money and concurrency

- zero, negative, exact, partial authorized and over-outstanding amounts;
- deposit versus balance allocation and already-paid targets;
- cash/external-terminal/bank-transfer currency mismatch;
- duplicate external reference with same/different amount and reservation;
- two record commands, two cash-shift opens, payment during shift close and refund during close;
- reversal/refund collision, repeated close/approval and transaction rollback after document dispatch failure.

### Security and authorization

- PAN-like values, CVV, expiry and track-like strings rejected from every tender field and omitted from logs/exceptions;
- Sales, Finance, Viewer and cross-property matrix;
- direct-object-reference attempts against payment, tender detail, shift, movement, evidence and receipt;
- redacted exports, document snapshots and audit entries.

### Cash operations

- zero/non-zero opening float, payment, refund, pay-in/pay-out and opposing correction;
- exact close, shortage, overage, threshold boundary and Finance approval/rejection;
- property-local date around midnight/DST with UTC audit instants;
- abandoned shift, disabled staff member and shift with pending variance.

### Browser journey

1. Front desk opens a cash shift.
2. Staff records an approved external-terminal deposit using only receipt-safe fields.
3. Retry/refresh proves one payment, folio line, deposit application and receipt.
4. Staff records a cash balance payment and downloads its receipt.
5. Shift close computes expected cash; a deliberate variance enters Finance review.
6. Finance approves the variance, requests and completes a partial external refund with evidence.
7. Viewer and cross-property contexts are denied throughout.

## 9. Release gates and handoff

The branch may merge only after focused tests, full SQLite, isolated PostgreSQL `inn_test` races, authenticated Playwright, Pint, PHPStan, ESLint, TypeScript, builds, OpenAPI, Docker health, dependency audits and `git diff --check` all pass.

Start after P3-06A merges:

```bash
git switch main
git pull --ff-only
git switch -c codex/p3-06b-front-desk-tenders
```

Do not use a real card number in fixtures, screenshots, logs or UAT. Test terminal behavior with synthetic receipt references only. Do not claim automatic card-present capture, terminal control or QR reconciliation; those begin in P3-06C.
