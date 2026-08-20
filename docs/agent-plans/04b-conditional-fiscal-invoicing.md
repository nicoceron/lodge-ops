# Agent 04B — Conditional jurisdiction-specific fiscal invoicing

## Copy/paste assignment

> Execute this assignment only if the client requires regulated fiscal issuance and has approved the legal entity, tax registration, jurisdiction, invoice classes, point of sale, numbering, tax/currency and cancellation/credit-note rules. Otherwise the coordinator must record a written deferment before final certification. Start after Agent 04. Read this file, the coordinator README, approved legal decision, P3-03 documents, commercial/payment/refund/folio code, Agent 10 kernel if an external execution adapter is used, and the current tax authority's official manuals completely. Build an app-owned immutable fiscal request/response ledger and a named jurisdiction adapter, prove homologation issuance/query/credit-note/idempotency, and keep operational receipts distinct from authorized fiscal documents. Never invent legal rules or use production certificates in tests.

## Branch, decision, and ownership

- Branch: `codex/p3-fiscal-<jurisdiction>` after Agent 04 and written legal/product approval. If the implementation uses Agent 10's connection/run/event kernel, Agent 10 must also be merged before branch creation.
- Evidence starts with legal entity/tax ID, jurisdiction, tax status, document classes, customer-ID thresholds, taxes, point of sale, numbering authority, currency/exchange source, issue/cancel/credit-note policy, retention, certificate owner, homologation/production environments and counsel/accountant approver.
- Own fiscal issuance requests, number authorization, authority adapter, immutable responses/events, fiscal PDF/data projection, credit/cancel lifecycle, reconciliation, UI/API/OpenAPI/tests/runbook.
- Do not rewrite P3-03 operational receipts/folios, the payment ledger, or commercial calculations. Fiscal facts reference their immutable snapshots.

## Domain and persistence

- `fiscal_connections`: tenant/legal entity/jurisdiction/environment/service/point of sale, certificate/key secret references, enabled/health/expiry; no private key/certificate bytes in DB or Git.
- `fiscal_document_requests`: source type/ID/version/checksum, document class, currency/rate, totals/taxes/customer fiscal projection, idempotency checksum, status, attempts and actor.
- Immutable `fiscal_authority_events/responses`: authority operation, request checksum, safe normalized response, authorization code/type/expiry, assigned number, occurred/received times and error/observation codes.
- Fiscal documents are append-only. A correction creates the jurisdiction-approved credit/debit/cancellation document linked to the original; never edits issued number/totals/customer/tax.
- Number identity includes legal entity, point of sale and document class. Reconcile against the authority's last-authorized/query endpoints before retrying an uncertain issuance.
- Separate operational document number/reference from the authority number and authorization. UI/exports make the distinction explicit.

## Adapter and execution

- Capability-specific authority adapter: authenticate, query catalogs/status, query last authorized, issue, query issued document and issue approved correction.
- Short-lived auth tickets/certificates resolved through secret manager; cache only within official lifetime and rotate with overlap. Environment/account mismatch fails closed.
- Stable application idempotency plus authority reconciliation handles timeout after remote success. Never request a second number until lookup proves the first absent.
- Queue after commit with explicit timeout/backoff/retryUntil/dead letter. Authority outage leaves an operational receipt and a visible pending fiscal request; it never fabricates an authorization.
- Validate authority catalogs/codes and approved currency rate at issue time while preserving the booked source snapshot.
- P3-03 renderer creates the legally reviewed layout/QR/data only from the authorized immutable fiscal response and template version.

## Argentina/ARCA default path, if approved

- Use ARCA WSAA plus the selected WSFEv1/WSMTXCA/WSCT service and official homologation endpoints; the accountant/legal decision chooses the service/document classes.
- Configure a distinct electronic point of sale and preserve sequential numbering/correlation per class.
- Support the approved factura classes and matching nota de crédito/débito types only; query official catalogs instead of hardcoding unstable descriptions.
- Persist CAE/CAEA type/code/expiry and authority observations. Homologation credentials/certificates are separate from production.
- Never claim an operational Inn PDF is an ARCA-authorized invoice without successful authority response and query reconciliation.

## Tests and real acceptance

- Catalog/auth success/expiry/rotation, wrong environment/entity/point of sale, invalid/expired certificate and secret redaction.
- Concurrent issuance, same/different idempotency body, timeout after authority success, response lost, last-number/query reconciliation, partial batch response and provider outage.
- Exact totals/taxes/rounding/currency rate, document-class/customer-ID boundaries, issue date/time-zone cutoff, leap/DST, original-to-credit-note limits and duplicate correction.
- PostgreSQL uniqueness and immutable history; role/property/entity/IDOR; no private key/token/customer tax ID leakage in logs/evidence.
- Homologation browser journey: Finance requests from closed folio → worker authenticates/issues → authoritative query confirms number/authorization → fiscal document downloads → approved correction issues and links → exports/reports reconcile.
- Production activation separately requires client production certificate/delegation, authorized point of sale, accountant-reviewed sample, real low-value issuance/correction and retention/operations sign-off.
- Run universal gates plus authority contract/homologation tests, credential scan and P3-03/payment/refund regression.

## Primary references

- Read the chosen jurisdiction's current official tax authority docs on implementation day and record exact versions in evidence.
- Argentina default: [ARCA electronic-invoice web-service documentation](https://www.arca.gob.ar/ws/documentacion/ws-factura-electronica.asp), [WS architecture/WSAA](https://www.arca.gob.ar/ws/documentacion/), [certificate environments](https://www.arca.gob.ar/ws/documentacion/certificados.asp), and [issuance/point-of-sale guidance](https://www.arca.gob.ar/fe/emision-autorizacion/solicitud-autorizacion.asp)
- [Laravel HTTP client](https://laravel.com/docs/13.x/http-client), [queues](https://laravel.com/docs/13.x/queues), and [encryption](https://laravel.com/docs/13.x/encryption)
