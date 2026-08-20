# Agent 15 — Final client release certification

## Copy/paste assignment

> Certify the complete Inn client release only after every required slice is merged and the production-like restored environment exists. Read this file, the coordinator README, durable Rincón Grande requirements, every slice plan/evidence/runbook, all current master status documents and the deployed commit. This is an evidence and integration assignment, not a place to invent last-minute features. Run a clean deployment and the entire state-changing staff → guest → finance → operations → provider → communication → integration → restore journey on phone and desktop; verify security, accessibility, performance, observability and operations. Reopen the owning slice for any defect. Update claims line by line so implemented, provider-gated, client-deferred and not implemented are unmistakable.

## Branch and authority

- Branch: `codex/client-release-certification` from synchronized `main` after Agents 00–14 plus conditional 04B that are in scope, with written deferments for every omitted capability.
- Agent 15 may update `docs/client-ready-phase-3-plan.md`, `docs/client-uat-ledger.md` and `docs/feature-matrix.md` only under coordinator review.
- Do not add a new business feature. Small test/runbook/status corrections are allowed; product defects go back to the owning slice and certification waits for that merge.
- Record exact deployed commit/image digests, environment, provider countries/modes, browser/tool versions and evidence location.

## Preflight truth reconciliation

- Compare every RG-4.1–4.11 and derived release requirement to an executable test/UAT evidence item and current code path.
- Reconcile stale documents. In particular, ensure P3-03 documents/exports status, P3-06A MCO versus ARS evidence, production communication/storage status and all integration claims agree everywhere.
- Create a deferment/decision register with requirement, reason, client/owner, date, impact, workaround and revisit date. An absent provider/account/legal decision is “blocked/pending,” not “implemented.”
- Fiscal invoice remains non-fiscal unless a reviewed jurisdiction-specific implementation and live acceptance exists.
- If regulated fiscal issuance is required, Agent 04B must pass homologation/production gates. Otherwise a written client deferment is mandatory before “Client-ready.”
- Physical Point is client-complete only with the actual authorized merchant hardware transaction/refund evidence. QR/virtual evidence must be labeled separately.
- Only the connector Agent 11 actually proved may be marketed. Explicitly mark other OTA/accounting/WhatsApp/e-signature connectors not implemented or deferred.
- Client KPI formulas require recorded client approval or written deferment. “Provisional” can support demo evidence but cannot satisfy a Client-ready verdict by itself.

## Fresh-environment certification setup

1. Provision a new production-like environment from immutable artifacts and secret references; no copied developer `.env`, DB volume, browser auth state or local object directory.
2. Run production preflight, backup checkpoint, isolated migrations, readiness and smoke.
3. Configure client-like property/time zone/currencies/resources/rates/programs/policies/templates/roles/provider connections without direct DB edits.
4. Restore the certified backup/checkpoint into a second isolated environment and run the same smoke/reconciliation there.
5. Seed only documented reference data. All UAT records are created through product paths and closed/revoked through normal lifecycle.

## Mandatory end-to-end client journey

- Manager configures property, rates/restrictions/promotion, programs/resources, policies, communication templates and integrations with authorized roles.
- Staff searches authoritative availability, quotes, holds, confirms, assigns room/guide/shared resources, sees exact calendar persistence and sends confirmation.
- Anonymous guest completes public direct booking in the supported locale/currency through hosted payment; signed event/authoritative lookup via ordinary worker confirms once.
- Public booking also exercises manual bank-transfer instructions/evidence/Finance approval when that payment choice is enabled, and property launch-readiness fail-closed then repair.
- Guest on 390×844 opens email/portal, acknowledges documents, updates companions/diet/pre-arrival, uploads clean evidence and downloads private confirmation/receipt.
- Cashier opens a property-local cash shift, records cash/pay-in or deposit, issues receipt, performs an authorized cash refund/movement, closes exact and then variance scenarios, and Finance approves the variance.
- Finance reviews bank transfer/private evidence, standalone terminal tender/manual refund, duplicate, Checkout Pro payment/refund/chargeback/settlement variance, exports XLSX/CSV and reconciles ledger totals.
- QR completes a real supported sandbox test-buyer order/event/refund/receipt. Point completes every supported virtual state including refunded; physical production device/card/refund evidence is required when Point is non-deferred, otherwise record the hardware gate/deferment explicitly.
- Operations checks in, sees kitchen update, handles normal/failed/overdue task, posts extras, moves/releases resources, checks out, settles/closes folio and updates housekeeping.
- Guest receives survey, submits once, and staff sees authorized result.
- Agent 11 connector completes its exact selected capability and direction, induced failure, replay and reconciliation. The default outbound webhook proves signed delivery/acknowledgment, not import.
- Queue/scheduler/provider retry/browser refresh and duplicate event produce no duplicated accounting, delivery, allocation or external mutation.
- Execute an action-by-role/property/download matrix with isolated browser sessions for Owner, Admin/Manager, Sales, Operations, Finance, Guide, Kitchen, Housekeeping and Viewer. Prove both each role's intended positive journey and denials for money, guest, guide, kitchen, housekeeping, configuration and private downloads; all other-property access is denied.

## Non-functional and failure gates

- Desktop, tablet and 390×844; English/Spanish; keyboard-only; WCAG 2.2 AA target with no unresolved critical/high automated or manual finding.
- Representative 90-day/high-volume calendar/search/quote/reports: record p50/p95, query count, error rate and agreed budgets. No N+1 or invariant failure under final-unit/voucher/payment races.
- Dependency, container, credential/secret, upload/malware, authorization/IDOR, security-header/TLS/cookie/CSP and log/PII redaction checks.
- Kill/restart API/worker/scheduler during provider/delivery/document/integration work; prove recovery without duplicates.
- Induce DB/Redis/object/email/payment/integration degradation and verify readiness/capability status/alerts/runbooks.
- Restore/rollback/rotated-secret rehearsal and achieved RPO/RTO evidence from Agent 14 remain valid for the certified commit.
- Restored-environment side-effect quarantine is re-proved: it boots without production credentials/outbound calls, reconciles pending work, registers sandbox callbacks and releases only selected queues.
- All CI jobs green on push and PR. Run the universal gates locally against PostgreSQL/production-like Compose as well.

## Final deliverables

- Final requirements traceability table: requirement → code surface → automated tests → browser/provider/runtime evidence → status → owner.
- Redacted release evidence manifest with checksums; no credentials, raw tokens, payment card data or unnecessary PII.
- Signed-off UAT ledger with pass/fail/deferred, date, environment and evidence for every row.
- Operations handoff index covering deployment, rollback, backup/restore, incident, provider/refund/dispute/settlement, queue/email, storage/privacy, integration and access management.
- Known limitations/risk register with severity, owner and due date.
- Release notes and exact honest product claims; remove scaffold-only or future-provider language from public/client materials.
- Merge only after independent review. Tag the certified commit only after `main` and `origin/main` are synchronized and the deployed digest matches.

## Release verdict wording

Use one of these, without softening failures:

- **Client-ready:** every non-deferred durable requirement and production gate passed on the certified environment.
- **Client-demo-ready, production blocked:** complete user journeys pass, but named provider/legal/infrastructure/restore gate is still open.
- **Not ready:** a required state-changing journey, money/inventory invariant, authorization boundary, provider outcome or recovery gate fails.

## Primary references

- [Rincón Grande requirements](../rincon-grande-requirements.md)
- [Laravel deployment](https://laravel.com/docs/13.x/deployment) and [Horizon](https://laravel.com/docs/13.x/horizon)
- [Filament 5 testing](https://filamentphp.com/docs/5.x/testing/overview)
- [Playwright projects](https://playwright.dev/docs/test-projects) and [trace viewer](https://playwright.dev/docs/trace-viewer)
- [WCAG 2.2](https://www.w3.org/TR/WCAG22/)
- [OWASP Application Security Verification Standard](https://owasp.org/www-project-application-security-verification-standard/)
