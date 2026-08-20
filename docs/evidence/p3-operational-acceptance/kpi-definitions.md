# Operational KPI definitions — provisional client approval boundary

Status: `provisional_client_approval_required`.

These definitions are implemented and reconcile to source rows, but they are not final Client-ready acceptance until the client explicitly approves the business meaning. The API intentionally returns the provisional status on every KPI response.

| KPI | Numerator | Denominator | Time basis | Currency | Exclusions and zero behavior | Reconciliation source |
| --- | --- | --- | --- | --- | --- | --- |
| Occupancy % | Occupied room nights | Active stay-resource capacity × local calendar days | Property-local dates converted to a half-open UTC window; departure day excluded | None | Draft, hold, cancelled, and no-show excluded; zero capacity returns `null` | Active reservations overlapping the window and active stay-category resource capacity |
| ADR | Immutable booked total | In-scope reservations in the same currency | Property-local overlap | One row per ISO currency; never FX-combined | Zero reservations returns `null` | `reservations.total_minor` grouped by currency |
| Revenue | Posted folio gross amount | None | `posted_at` inside the half-open UTC window | One row per ISO currency | Rows outside the period excluded | `folio_lines.gross_amount_minor` grouped by currency |
| Deposit received | Succeeded payment amount | None | Reservation scope for the selected period | One row per ISO currency | Pending, failed, reversed, and refunded statuses excluded from received cash | `payments.amount_minor` where status is `succeeded` |
| Outstanding | Booked total less succeeded payments | None | Reservation scope for the selected period | One row per ISO currency | Floor at zero | Reservations less succeeded payments |
| Overdue tasks | Tasks due before the audit instant | None | UTC instant, displayed in property-local time | None | Done, cancelled, and superseded excluded | `operational_tasks.due_at` and lifecycle status |

Client approval must explicitly resolve whether ADR should instead use occupied room nights, whether occupancy includes held inventory, and whether revenue should use service date rather than posting date. No implementation default should be represented as approved until that decision is recorded by the coordinator.
