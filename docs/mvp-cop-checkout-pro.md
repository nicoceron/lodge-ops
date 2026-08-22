# Functional COP Checkout Pro MVP

Date: 2026-08-21
Status: calendar merged; backend and public `/book` still in progress.

This is the cut-down mission plan. It does **not** replace coordinator docs (`docs/client-ready-phase-3-plan.md`, `docs/client-uat-ledger.md`, `docs/feature-matrix.md`) and does **not** mark unrelated slices complete.

## Outcome

Deliver a working lodge MVP on the existing Inn stack:

1. Staff operational calendar and assignments (PR #14 — **merged**).
2. Public Laravel `/book/{slug}` direct booking against the Inn API.
3. Mercado Pago Checkout Pro in **COP** (`site=MCO`).
4. Signed webhook through the ordinary worker → payment → folio → receipt → reservation confirmation.
5. Basic cancellation/refund and already-shipped cash / bank-transfer / external-terminal recording.
6. Local Compose plus an ngrok HTTPS tunnel. Cheap VPS is documented later, not provisioned.

## Merge sequence

| Step | Work | Status |
| --- | --- | --- |
| 1 | Undraft and merge PR #14 (`codex/p3-operational-acceptance`) | **Done** — `512c2c3` on `origin/main` |
| 2 | Finish Agent 07 dirty direct-booking API, independent review, green CI, merge | In progress on dirty `agent-07` worktree |
| 3 | Finish Agent 08 Laravel `/book` UI against that API, independent review, green CI, merge | In progress on dirty `agent-08` worktree |
| 4 | Compose + ngrok, published COP rate, one sandbox Checkout Pro journey, handoff | Not started |

KPIs stay **provisional/beta**. Do not wait for client KPI sign-off. Operational KPI definitions landed with PR #14 as `provisional_client_approval_required` in `docs/evidence/p3-operational-acceptance/kpi-definitions.md`.

## After PR #14 (now on main)

Staff Filament at `http://localhost:8000/manage` includes:

- Master Calendar filters, property-local dates, and compact 390×844 agenda
- Shared-resource assign / move / swap / release via guarded commands
- Inquiry → proposal → reservation path from the operational-acceptance branch
- Cash, bank-transfer, and standalone external-terminal tenders (already on main from P3-06B)
- Cancel and refund controls (already on main)

Demo staff: `admin@example.com` / `password` (tenant Estancia Viento Sur). Do not commit extra credentials.

## Explicitly not blocking this MVP

Point/QR and physical terminals, Argentina/ARS, Resend certification, generic connectors, fiscal invoicing, **final** KPI definitions, enterprise observability/DR, the 15-agent completion pack, token rotation, Next.js marketing rewrite, Turnstile production certification (`bot_verification_required=false` is acceptable), Cloudflared (ngrok now; swap later).

## Worktree rules

- Main: `/Users/ceron/Developer/Projects/lodge-ops`
- Calendar (merged): `/Users/ceron/Developer/Projects/lodge-ops-worktrees/agent-05` — do not mutate after merge
- Direct-booking API: `/Users/ceron/Developer/Projects/lodge-ops-worktrees/agent-07` — **DIRTY**. Never `git reset --hard`, `git clean -fd`, or `git stash drop`.
- Public `/book` UI: `/Users/ceron/Developer/Projects/lodge-ops-worktrees/agent-08` — **DIRTY**. Same no-reset rule. Rebase onto `origin/main` only after the Agent 07 API is merged if the UI depends on those routes; calendar rebase after PR #14 is already done.

Rebase protocol when needed: `git stash push -u` → `git fetch origin` → `git rebase origin/main` → `git stash pop`.

## Runtime (later deploy/UAT)

Reuse Compose: Laravel API `:8000`, Next web `:3000` (marketing only), PostgreSQL `:5432`, Redis `:6379`, one ordinary worker (`provider-events` and `documents` required), scheduler, ngrok HTTPS → API only. Keep the demo tenant; **add** a published COP rate and Checkout Pro connection (`site=MCO`, `charge_currency=COP`, `return_url_base=https://<tunnel>`).
