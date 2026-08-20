# P3-06B front-desk tenders operations runbook

Date: 2026-08-20

## Scope and truth boundary

Inn records money that staff already received as cash, by bank transfer, or on a standalone external terminal. A standalone-terminal record is always `channel = external_terminal`, `entry_mode = staff_recorded`, and `origin = manual`. Inn does not send an amount to the device, authorize or capture a card, verify processor settlement, or control Point/QR hardware in this slice.

Never enter a PAN, expiry date, CVV, PIN, magnetic-stripe data, chip data, NFC payload, or terminal receipt dump. The allowlist is processor alias, merchant-account alias, terminal identifier, transaction/authorization reference, batch reference, optional card brand, and optional last four digits. The detector is defense in depth, not proof of PCI compliance or SAQ eligibility. PCI SSC says sensitive authentication data cannot be stored after authorization, even when encrypted, and that SAQ eligibility depends on the merchant's actual payment-channel environment: [SAD retention FAQ 1533](https://www.pcisecuritystandards.org/faqs/1533/), [SAQ B-IP FAQ 1313](https://www.pcisecuritystandards.org/faqs/1313/), and [PAN truncation FAQ 1091](https://www.pcisecuritystandards.org/faqs/1091/).

## Roles

| Operation | Allowed roles |
| --- | --- |
| Record cash, bank transfer, standalone-terminal, or manual-other tender | Administrator, Manager, Operations, Finance |
| Open or operate own cash shift | Administrator, Manager, Operations |
| Resolve duplicate/identity exception | Administrator, Manager, Finance |
| Request/complete a controlled manual refund or approve evidence/variance | Administrator, Manager, Finance |
| Any payment mutation | Sales and Viewer denied |

All records remain tenant and property scoped. A role grant never overrides property membership.

## Start and operate a cash shift

1. Open **Finance → Cash Shifts** and choose the current property, ISO currency, and counted opening float in minor units.
2. Confirm the business date uses the property's configured timezone. Audit timestamps remain immutable UTC instants.
3. Record a pay-in or pay-out only for a physical drawer movement and enter a bounded reason. A pay-out cannot exceed expected cash.
4. Never edit a movement. Correct one with an exact signed opposing movement linked to the original.
5. Record cash against a reservation only while the current actor owns a matching open property/currency shift.

Expected cash is derived as opening float + posted cash tenders - completed cash refunds + pay-ins - pay-outs + opposing corrections. Staff never submit expected cash as truth.

## Record a tender

From the reservation **Payments** tab, choose **Record front-desk payment**:

- Cash: confirm the matching shift is open, then enter only the amount/allocation.
- Bank transfer: enter the bank-safe transfer reference when available. Guest evidence remains private and Finance-reviewed.
- Standalone external terminal: process the card outside Inn first. Enter all required non-secret receipt identity fields. The receipt says “Recorded external terminal payment; Inn did not charge the card.”
- Manual other: use only for a documented non-card external tender and include a reason.

The server derives reservation, property, tenant, currency, manual origin, staff entry mode, folio effect, deposit application, and receipt intent. A retry with the same command key and body replays the original outcome; the same key with a different body fails.

## Duplicate and missing-identity review

A terminal record missing processor, merchant-account alias, terminal identifier, or transaction/authorization reference is held as an unposted identity exception. A normalized identity already present at the property is held as `duplicate_review`. Neither state creates a Payment, deposit application, folio line, cash movement, or receipt.

Finance reviews **Payment Tender Details** and records one audited decision:

- confirmed duplicate: retain the draft, post no money;
- needs corrected identity: retain for follow-up;
- corrected identity: rerun the typed payment command and duplicate check with corrected safe fields;
- dismiss unposted draft: retain the disposition without posting.

Do not delete exception history or bypass the duplicate check with blank fields.

## Manual refunds and evidence

1. Finance requests a refund against one succeeded manual-origin Payment. The command caps the request to the remaining reversible payment amount and current guest credit.
2. For bank-transfer, external-terminal, and manual-other refunds, staff execute the refund outside Inn and upload the execution receipt through the private refund-evidence endpoint. Inn scans the temporary upload before persistence; only a successful content/MIME and scanner result is stored on the private disk.
3. Finance reviews the evidence linked to that exact refund request. Pending or rejected evidence cannot be downloaded or used for completion.
4. Finance completes the refund with the external execution reference and approved evidence. Inn records completion; it does not claim provider confirmation.
5. A cash refund instead requires the completing actor's matching open shift and atomically appends a negative drawer movement.

When the scanner is unavailable, upload fails closed. Do not move the file to a public disk or approve it manually; restore the scanner and resubmit. Laravel's file validation determines MIME from file contents, and private storage/download behavior must remain explicit: [Laravel validation](https://laravel.com/docs/13.x/validation#validating-files) and [Laravel filesystem](https://laravel.com/docs/13.x/filesystem).

## Close, variance, and forced close

Enter counted cash and a reason for every non-zero variance. Within the configured threshold the shift closes; above it the shift enters `variance_review` until Administrator, Manager, or Finance approval. Approval never rewrites payments or movements.

Forced close is limited to Administrator/Manager and only when the cashier is disabled or the shift exceeds the configured stale-shift interval. Record the operational reason. A normal close and every money command use database transactions and row locks so partial effects roll back together: [Laravel database transactions](https://laravel.com/docs/13.x/database#database-transactions).

## Reconciliation checks

For each shift, compare the private CSV shift report to the physical drawer count:

- one opening-float movement;
- one movement per posted cash payment and completed cash refund;
- all pay-ins/pay-outs and linked opposing corrections;
- derived expected cash, counted cash, variance, close actor/time, and approval actor/time.

For each reservation, compare the Payment, tender detail, folio credit, optional deposit application, and generated receipt. A posted tender must have one of each applicable effect. A duplicate/identity exception must have none of them.

## Incident handling

- Scanner unavailable or content rejected: keep the refund request open; do not persist or serve the rejected artifact.
- Duplicate identity: stop posting and route the exception to Finance.
- Crash after commit: retry the exact same command key; do not invent a replacement identity.
- Stale shift: Manager/Admin verifies cashier status and physical count before forced close.
- Currency mismatch or closed/cancelled/no-show reservation: stop and correct the operational source record; never override server-derived currency/state.
- Suspected card data in a note/reference/log: stop, restrict access, follow the incident process, and do not copy the value into tickets or screenshots.

## Release evidence boundary

Release evidence may establish controlled recording, accounting idempotency, private evidence handling, receipts, and PostgreSQL locking. It must not claim integrated terminal operation, automatic authorization/capture, processor settlement accuracy, PCI compliance, SAQ qualification, or Point/QR support.
