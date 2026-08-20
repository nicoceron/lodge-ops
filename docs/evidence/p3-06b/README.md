# P3-06B execution evidence

This directory records reproducible, redacted evidence for cash, bank-transfer, and standalone-terminal recording. It contains no credentials, full card data, private guest artifacts, or production processor data.

## 2026-08-20 isolated PostgreSQL browser journey

Environment:

- worktree branch `codex/p3-06b-front-desk-tenders`;
- isolated PostgreSQL database `inn_uat_agent01`;
- current API worktree served at `http://127.0.0.1:18001`;
- authenticated Administrator session on tenant `demo-lodge`;
- synthetic receipt references and last four only.

Observed state-changing journey:

1. Opened a USD shift with USD 100.00 float.
2. Recorded USD 500.00 cash and observed `Succeeded / Cash / Manual`.
3. Recorded USD 600.00 bank transfer and observed `Succeeded / Bank Transfer / Manual` with normalized safe reference.
4. Recorded USD 700.00 standalone-terminal tender with synthetic processor/merchant/terminal/reference, `Test brand`, and `0042`; observed `Succeeded / External Terminal / Manual`.
5. Submitted the same terminal identity again. The Payments table remained at three payments, while Finance received one `duplicate_review` exception. Finance marked it `confirmed_duplicate`; no payment or folio effect was added.
6. Added USD 50.00 pay-in. Current expected cash moved from USD 600.00 to USD 650.00.
7. Closed at USD 640.00. The shift recorded expected USD 650.00, variance -USD 10.00, entered `variance_review`, and then closed after approval.
8. Browser error/warning console: empty.

Post-journey PostgreSQL assertions:

- three posted reservation tenders: cash 50,000 minor, bank transfer 60,000 minor, external terminal 70,000 minor;
- all three `origin = manual`, `entry_mode = staff_recorded`, currency USD;
- external terminal preserved safe last four `0042` and the normalized reference;
- one confirmed duplicate plus three posted tender-detail rows;
- exactly three payment folio credits and three generated payment receipts;
- shift movements: opening float 10,000, payment 50,000, pay-in 5,000;
- closed shift: expected 65,000, counted 64,000, variance -1,000.

The checked-in authenticated Playwright journey then exercised the complete acceptance sequence against the same isolated PostgreSQL runtime with a fresh deterministic reservation. Its temporary API credential was transferred through a mode-`0600` container handoff, excluded from tracing, removed immediately, and revoked in `finally` cleanup. The passing `1/1` journey proved:

- USD 100.00 opening float, cash USD 100.00, bank transfer USD 200.00, and standalone-terminal USD 700.00 exactly settled a USD 1,000.00 reservation;
- the bank row rendered `Bank Transfer / Manual`, while the terminal row rendered `External Terminal / Manual` and retained only `Test brand / 0042` plus synthetic references;
- a repeated terminal identity created `duplicate_review`, posted no second payment, and was resolved as a confirmed duplicate;
- a synthetic application-level credit created a truthful refundable guest balance; the USD 100.00 external-terminal refund required uploaded, scanned, approved evidence linked to that exact request;
- the approved private PDF downloaded only through the authenticated tenant route with `Cache-Control: no-store` and `application/pdf` content;
- check-in and checkout completed, followed by a USD 100.00 cash refund that atomically appended the -10,000 drawer movement;
- expected drawer cash returned to 10,000 minor, a 9,900 count entered `variance_review`, and Finance approval closed the shift;
- the folio balance was zero before the checked-out folio closed;
- the generated terminal receipt PDF contained `Recorded external terminal payment; Inn did not charge the card`;
- the final authenticated reservation, generated-document, and cash-shift pages rendered without server errors.

## Reproduction commands

Run the focused PostgreSQL tender and race tests against a dedicated database:

```bash
./vendor/bin/phpunit --configuration phpunit.pgsql.xml \
  tests/Feature/FrontDeskTenderTest.php \
  tests/Feature/FrontDeskTenderControlsTest.php \
  tests/Feature/FrontDeskTenderMigrationTest.php \
  tests/Feature/PostgresFinancialConcurrencyTest.php
```

Run the universal application gates from `apps/api`:

```bash
./vendor/bin/phpunit
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=1G
```

Verify the contract from the repository root:

```bash
make contract
```

Run the authenticated P3-06B browser journey against the local Compose API service:

```bash
INN_FRONT_DESK_COMPOSE_UAT=1 \
  npm --prefix apps/web run e2e:client -- \
  e2e-client/p3-06b-front-desk-tenders.spec.ts
```

See [the operations runbook](../../p3-06b-front-desk-tenders-runbook.md) for operational procedures and explicit non-claims.
