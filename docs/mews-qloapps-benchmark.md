# Mews and QloApps benchmark audit

Audit date: 2026-08-12

This audit compares LodgeOps with the maintained Mews Connector API documentation and repository, the QloApps reference implementation, and the current Filament/Livewire presentation guidance. It records product decisions without importing third-party source code.

## Sources reviewed

- [Mews Connector API](https://docs.mews.com/connector-api), including authentication, request guidelines, reservations, availability blocks, webhooks, deprecations, changelog, and certification guidance.
- [MewsSystems/open-api-docs](https://github.com/MewsSystems/open-api-docs), the active documentation repository. The older `gitbook-connector-api` repository is archived and is not treated as current authority.
- [Qloapps/QloApps](https://github.com/Qloapps/QloApps), including reservation lifecycle, room assignment, overbooking, arrival/departure reporting, refund and cancellation behavior, and dashboard presentation patterns.
- [Filament panel configuration](https://filamentphp.com/docs/5.x/panel-configuration) and [Livewire 4 documentation](https://livewire.laravel.com/docs/4.x/quickstart) for navigation, authorization, loading states, and reactive UI patterns.

## Findings and implementation

| Finding | LodgeOps decision | Delivered evidence |
| --- | --- | --- |
| A planned stay status is not enough to reconcile real operations. | Capture immutable operational facts for actual check-in and check-out time. | Reservation lifecycle migration, API resource, Filament infolist, and `ReservationStatusTest`. |
| Cancellation and no-show operations need accountable reasons and must release inventory. | Require a closure reason, timestamp the closure, preserve it in status history/outbox data, and release allocations for both outcomes. | Reservation service, API validation, Filament action modal, and lifecycle tests. |
| A daily agenda alone is weak for room/resource assignment. | Add a native Livewire resource-lane planner with sticky headers, availability blocks, operational lenses, responsive mobile agenda, and week/2-week/30-day navigation. | Master Calendar page and Filament workspace tests. |
| Mews credentials are a Client Token plus Access Token, with separate demo and production hosts. | Keep tokens behind an `env://` secret reference and hard-allowlist only official Mews hosts. Never persist or return credential material. | `IntegrationSecretResolver`, `MewsConnectorClient`, and `MewsIntegrationTest`. |
| Mews clients must tolerate throttling and transient upstream failures. | Add bounded retries for HTTP 429 and 5xx/connection failures, then surface a controlled health result. | Mews client fake-response tests. |
| Provider activation should be observable before enabling data flow. | Add a Filament **Test connection** action and idempotent API endpoint that records last check, safe enterprise metadata, and a redacted error. | Integration connection resource, health service, API contract, and seeded disconnected connection. |
| Filament can fail open when resource authorization methods are accidentally omitted. | Enable strict authorization and fill the missing policies, including nested property-bound retail records. | Panel provider, policy suite, Filament resource tests. |
| Product branding and high-density operational pages should use the available workspace. | Apply LodgeOps branding, full-width content, transactions, SPA navigation/prefetch, and intentionally collapsed secondary navigation groups. | Panel provider and browser verification. |

## Mews synchronization activation plan

The connector currently stops at credential-safe connectivity by design. Automatically importing reservations before a tenant chooses the authoritative system, maps Mews services/resources to LodgeOps properties, and provides credentials would risk duplicate bookings and destructive reconciliation.

When a tenant activates synchronization, implement it behind the existing integration boundary in this order:

1. Map Mews Enterprise, services, resource categories, and resources to one LodgeOps tenant/property.
2. Bootstrap with the current cursor-paginated `reservations/getAll/2023-06-06` operation and dedicated companion/customer/resource operations; do not depend on deprecated response extents.
3. Consume Mews webhooks as hints, then re-fetch the affected entity. Use overlapping `UpdatedUtc` windows plus persisted cursors as the recovery path.
4. Preserve provider IDs and revision/checkpoint data in an integration mapping table, and make every inbound command idempotent.
5. Start read-only, reconcile counts and conflicts, then permit explicitly configured write directions.
6. Complete Mews demo-environment and certification checks before switching the connection to production.

## Deliberate dependency choices

- The third-party Filament FullCalendar package was not installed because its published compatibility matrix did not advertise Laravel 13 support at audit time. The native Livewire planner avoids pinning the application to an incompatible framework range and matches LodgeOps' generalized resources better than an event-only calendar.
- Flux UI was not added because the staff application already uses Filament's accessible component system. A second component library would add CSS and interaction inconsistency without filling a missing workflow.
- QloApps behavior was used as a product reference only. No QloApps code or licensed assets were copied into LodgeOps.
