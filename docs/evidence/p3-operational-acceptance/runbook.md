# Operational acceptance runbook

Use a dedicated Compose project and ports so this journey cannot collide with another worktree.

## Manager journey

1. Sign in as the seeded manager and select the seeded tenant.
2. Open **Master calendar**. Exercise every filter and confirm program colors, conflict counts, local dates, resource lanes, and keyboard focus order. Repeat at `390 × 844`; the compact agenda must replace the wide planner without horizontal page overflow.
3. Create or select a repeat guest. Search duplicate hints, merge a known duplicate into the canonical guest, and verify stay history remains visible without exposing full phone/email values in the search response.
4. Create a manual proposal with an inquiry source, category, published rate plan, stay, and occupancy. Confirm no staff-entered total field is available, send it, and convert it twice with the same intent; only one held reservation may exist.
5. Edit companions. Confirm ordering and dietary/allergy preferences appear in the kitchen projection; exceeding the priced occupancy must require an amendment.
6. Publish two checklist versions. Generate v1, start one task, generate v2, and verify only untouched pending v1 tasks become superseded.
7. Fail, reopen, escalate, and complete a task. Confirm the reason, revision, and event timeline remain visible. Amend the arrival and confirm untouched task due times move by the same offset. Cancel the reservation and confirm open tasks cancel while completed history remains.
8. Open the KPI endpoint/report and reconcile each displayed value to its source rows. Record only provisional acceptance until the client resolves the decisions listed in `kpi-definitions.md`.

## Guide denial journey

1. Sign in as the seeded Guide.
2. Confirm the calendar contains only reservations allocated to the Guide’s linked crew resource, the Guide’s own blocks, and tasks assigned to that Guide.
3. Create and edit an availability block for the Guide’s own resource.
4. Attempt another Guide’s resource block, task, guest directory, reservation directory, payments, and KPI endpoint. Each must be denied without leaking record data; the cross-resource block uses a validation-safe `422` denial and the restricted domains use `403`.

## Required gates

- SQLite and PostgreSQL suites, including overlap/capacity race tests.
- Focused operational closure, commercial pricing, payments, provider lifecycle, and front-desk tender regressions.
- Filament resource/page render tests and OpenAPI route parity.
- Static analysis, Pint, web lint/typecheck/build, dependency audit, secret scan, and `git diff --check`.
- Full state-changing manager journey on desktop and `390 × 844`, plus the separate Guide denial journey, with browser receipts and console/network-error review.
