# P3-08A remediation UAT receipt

Date: 2026-08-20

Environment: rebuilt local Compose stack, PostgreSQL 18, Redis worker, Laravel API, Next.js public web

Scope: deterministic sandbox fixtures and local browser observation only; no real provider or production integration is claimed.

## Compose provider journey

1. `make up` rebuilt the API, worker, scheduler, migration, and web images; every required health check passed.
2. The local-only Mercado Pago Checkout Pro fixture created a canonical property connection using secret references and a rotating callback endpoint. Ephemeral fixture values were injected only into the API and worker processes for this run and removed afterward.
3. Hosted checkout creation completed for ARS 100.00.
4. An exact raw JSON webhook body was signed with the Mercado Pago manifest and posted through the public callback route.
5. The callback returned HTTP 200 and the ordinary Redis worker consumed the event.
6. Database observation after the worker completed showed:
   - payment attempt state: `approved`
   - provider event count for the connection: `1`
   - provider payment ledger count for the external payment identity: `1`

All tenant, property, reservation, attempt, provider-payment, endpoint-key, signature, and secret values are intentionally omitted from this checked-in receipt.

## Visible browser journey

The property-scoped administrator session loaded these Filament surfaces from `http://localhost:8000/manage/workspace/demo-lodge`:

- **Integration Connections** rendered the deterministic sandbox connection with canonical provider `mercado_pago`, product `checkout_pro`, environment `sandbox`, and status `Connected`.
- **Non-secret configuration** rendered the callback secret reference as `[configured]` and the deterministic fixture as `[test fixture configured]`; neither the reference URI nor any value appeared in the table.
- The connection exposed the authorized View, Edit, Test, Disable, Start run, Reconcile, Rotate endpoint key, and Revoke actions.
- **Integration Sync Runs** loaded with its status, page, success, dead-letter, and safe-error columns.
- **Integration Mappings** loaded with versioned capability/direction/local/external identity and conflict-state columns plus the authorized creation action.

This browser receipt verifies the rendered administration and redaction boundary. Transactional disable/resume, mapping version closure/drift, replay, cursor recovery, property isolation, and concurrency behavior are covered by the executed SQLite and PostgreSQL suites listed in the evidence index.

## Automated browser receipts

- Public web Playwright: 4 passed.
- Closed-loop client Playwright: 8 passed, 1 expected provider-mode skip.
