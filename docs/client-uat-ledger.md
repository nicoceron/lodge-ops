# Client UAT ledger

This ledger is the release-facing evidence index for client requirements 4.1–4.11. Automated domain tests remain necessary, but a capability is not marked client-complete until its authenticated journey passes.

| Requirement | Journey | Automated evidence | Current release state |
| --- | --- | --- | --- |
| 4.1 | Calendar and resource lanes | `UAT-4.1`, `FilamentWorkspacePagesTest`, `AllocationConflictTest` | Authenticated render gate passed; mobile/role/performance interaction gate pending |
| 4.2 | Guides and operational resources | `UAT-4.1`, `ResourceSuggestionTest`, `GenericOperationalFlowsTest` | Domain-complete; allocation workbench follow-on |
| 4.3 | Availability-first reservation and repeat guests | `UAT-4.3`, `N2 guarded amendment…`, `ClientBookingCoreTest`, `ReservationChangesTest`, `FilamentResourcesTest` | Browser create → confirm → amend → move passed; repeat-guest/companion and calendar-persistence mutation assertions remain |
| 4.4 | Email and internal notifications | `CommunicationDeliveryTest`, `OutboxAutomationTest` | Local domain-complete; production provider deferred |
| 4.5 | Deposit and bank-transfer evidence | `UAT-4.5`, `N2 guarded amendment…`, `PaymentEvidenceReviewTest`, `ReservationChangesTest`, `CommercialWorkflowTest` | Browser manual record/reconcile passed; guest upload → finance evidence approval and selected-deposit browser journey remain |
| 4.6 | Finance projections | `UAT-4.1`, `FinanceReportingTest` | Authenticated browser gate passed; real downloadable exports deferred |
| 4.7 | Kitchen and dietary planning | `UAT-4.1`, `FilamentKitchenDashboardTest` | Authenticated browser gate passed |
| 4.8 | Tasks and checklists | `UAT-4.8 through 4.10`, `GenericOperationalFlowsTest` | Authenticated render gate passed; state-changing task journey pending |
| 4.9 | Extras and final folio | `UAT-4.8 through 4.10`, `N2 guarded amendment…`, `ReservationChangesTest`, `CommercialWorkflowTest`, `GuestPortalWebTest` | Cancellation fee/refund/zero-balance browser loop passed; check-in → extra → checkout → folio close and final documents remain |
| 4.10 | Post-stay survey | `UAT-4.8 through 4.10`, `GuestPortalWebTest`, `FilamentSurveyResponseTrackerTest` | Authenticated render gate passed; closed-loop invitation/response browser journey pending |
| 4.11 | Role boundaries | `RoleSemanticsTest`, `TenantIsolationTest`, `PaymentEvidenceReviewTest`, `ReservationChangesTest` | Automated role/tenant gate includes N2 Sales/Operations/Finance actions; browser role matrix remains |

The authenticated suite runs with `make test-client` against seeded Laravel/PostgreSQL. It now contains five tests: four authenticated smoke/render journeys and one state-changing N2 financial/reservation journey. The remaining state-changing expansion and edge-case matrix are defined in the [phase 3 plan](client-ready-phase-3-plan.md). Provider-dependent gateway, channel, accounting, e-signature, SMS/WhatsApp, and production mail journeys remain explicitly deferred until a provider and sandbox account are selected.
