# Client UAT ledger

This ledger is the release-facing evidence index for client requirements 4.1–4.11. Automated domain tests remain necessary, but a capability is not marked client-complete until its authenticated journey passes.

| Requirement | Journey | Automated evidence | Current release state |
| --- | --- | --- | --- |
| 4.1 | Calendar and resource lanes | `UAT-4.1`, `FilamentWorkspacePagesTest`, `AllocationConflictTest` | Authenticated browser gate passed |
| 4.2 | Guides and operational resources | `UAT-4.1`, `ResourceSuggestionTest`, `GenericOperationalFlowsTest` | Domain-complete; allocation workbench follow-on |
| 4.3 | Availability-first reservation and repeat guests | `UAT-4.3`, `ClientBookingCoreTest`, `FilamentResourcesTest` | Client-complete staff hold slice |
| 4.4 | Email and internal notifications | `CommunicationDeliveryTest`, `OutboxAutomationTest` | Local domain-complete; production provider deferred |
| 4.5 | Deposit and bank-transfer evidence | `UAT-4.5`, `PaymentEvidenceReviewTest`, `CommercialWorkflowTest` | Client-complete manual-transfer slice |
| 4.6 | Finance projections | `UAT-4.1`, `FinanceReportingTest` | Authenticated browser gate passed; real downloadable exports deferred |
| 4.7 | Kitchen and dietary planning | `UAT-4.1`, `FilamentKitchenDashboardTest` | Authenticated browser gate passed |
| 4.8 | Tasks and checklists | `UAT-4.8 through 4.10`, `GenericOperationalFlowsTest` | Authenticated browser gate passed |
| 4.9 | Extras and final folio | `UAT-4.8 through 4.10`, `CommercialWorkflowTest`, `GuestPortalWebTest` | Domain-complete; final invoice deferred |
| 4.10 | Post-stay survey | `UAT-4.8 through 4.10`, `GuestPortalWebTest`, `FilamentSurveyResponseTrackerTest` | Authenticated browser gate passed |
| 4.11 | Role boundaries | `RoleSemanticsTest`, `TenantIsolationTest`, `PaymentEvidenceReviewTest` | Automated role/tenant gate |

The authenticated suite runs with `make test-client` against the seeded Laravel application. The current gate is 4 authenticated staff journeys; together with the two public journeys across desktop and mobile projects, the repository executes 8 Playwright tests. Provider-dependent gateway, channel, accounting, e-signature, SMS/WhatsApp, and production mail journeys remain explicitly deferred until a provider and sandbox account are selected.
