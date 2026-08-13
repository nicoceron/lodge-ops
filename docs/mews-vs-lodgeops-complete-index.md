# Mews vs LodgeOps — complete feature-by-feature audit index

Audit date: 2026-08-13.

This is the complete handoff from the row-level verification passes. The comparison is split by scope so every feature remains individually searchable instead of being hidden inside a summary. The GitHub/OpenAPI sources named in the attached Mews research were audited separately and are linked below.

## Complete matrices

- [PMS, reservations, front desk, rates, space, guest journey, housekeeping, upsells, Digital Key, kiosk, concierge, groups, MICE, and current Mews OS additions](mews-vs-lodgeops-complete-pms.md)
  - 295 original product-capability rows plus 9 current Mews OS additions.
- [Payments, payment methods, processing, tokenization, reconciliation, accounting, AR, multicurrency, terminals, financing, revenue management, rates, BI, and finance API parity](mews-vs-lodgeops-complete-finance.md)
  - Row-level finance/revenue inventory, including every separately identified payment method and reporting/rate capability.
- [Connector API, Booking Engine API, Channel Manager API, POS API, webhooks, WebSockets, authentication, pagination, retries, and certification mechanics](mews-vs-lodgeops-complete-protocol.md)
  - 288 baseline protocol/event/operation rows, plus the live-contract and SDK/API-space addendum in the [GitHub/OpenAPI re-audit](mews-vs-lodgeops-github-openapi-audit.md).
- [Marketplace, integration classes, Mews first-party products, groups/chains, security/compliance, Open API, and public website stack](mews-vs-lodgeops-complete-ecosystem.md)
  - Marketplace, enterprise, security, and public-stack rows.
- [GitHub/OpenAPI re-audit of the attached research links](mews-vs-lodgeops-github-openapi-audit.md)
  - Live Connector Swagger comparison, first-party API repository, Booking Engine web surfaces, Payments Checkout, Loyalty Partner, POS mechanics/use cases, allowances, VAT/restrictions, and public Mews repositories.
- [Up-to-date technology-by-feature audit](mews-vs-lodgeops-technology-audit.md)
  - Mews’ disclosed Azure/C#/.NET/React/TypeScript/Flutter/Kotlin platform stack, every public API/browser/device/payment technology surface, public repository signals, and the remaining private-architecture boundary.

## Status definitions

- **FULL** — a materially equivalent executable LodgeOps capability exists.
- **PARTIAL** — LodgeOps has a narrower or internal analogue, but not the Mews workflow or scope.
- **ABSENT** — no executable implementation was found.
- **ADAPTER-ONLY** — configuration or secret storage exists, but no provider adapter/execution path exists.

## LodgeOps evidence baseline

The workers checked the implementation, not just the intended feature documentation:

- [API routes](/Users/ceron/Developer/Projects/lodge-ops/apps/api/routes/api.php)
- [API package manifest](/Users/ceron/Developer/Projects/lodge-ops/apps/api/composer.json)
- [IntegrationConnection.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Models/IntegrationConnection.php)
- [IntegrationConnectionService.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Services/IntegrationConnectionService.php)
- [PaymentService.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Services/PaymentService.php)
- [FinancialReportingService.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Services/FinancialReportingService.php)
- [ReservationService.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Services/ReservationService.php)
- [GuestPortalService.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Services/GuestPortalService.php)
- [web package manifest](/Users/ceron/Developer/Projects/lodge-ops/apps/web/package.json)

## Official Mews source families used

- [Mews PMS](https://www.mews.com/en/property-management-system)
- [Reservation Management](https://www.mews.com/en-gb/products/reservation-management)
- [Booking Engine](https://www.mews.com/en/products/booking-engine)
- [Payments](https://www.mews.com/en/products/payments)
- [Accounts Receivable](https://www.mews.com/en/products/accounts-receivable)
- [Tokenization](https://www.mews.com/en/products/tokenization)
- [Automated Reconciliation](https://www.mews.com/en/products/automated-reconciliation)
- [Multicurrency](https://www.mews.com/en/products/multicurrency)
- [Terminals](https://www.mews.com/en-gb/products/terminals)
- [Revenue Management System](https://www.mews.com/en/products/revenue-management-system)
- [Dynamic Pricing Automation](https://www.mews.com/en/products/dynamic-pricing-automation)
- [Mews BI](https://www.mews.com/en/products/mews-bi)
- [Marketplace](https://www.mews.com/en/products/marketplace)
- [Groups & Chains](https://www.mews.com/en/solutions/groups)
- [Connector API operations](https://docs.mews.com/connector-api/operations)
- [Live Connector Swagger](https://api.mews.com/Swagger/connector/swagger.yaml)
- [MewsSystems/open-api-docs](https://github.com/MewsSystems/open-api-docs)
- [Booking Engine API operations](https://docs.mews.com/booking-engine-guide/booking-engine-api/operations)
- [Channel Manager API operations](https://docs.mews.com/channel-manager-api/mews-operations)
- [POS API operations](https://docs.mews.com/pos-api/operations)
- [Mews Platform Documentation](https://www.mews.com/en/legal/platform-documentation)

## Important scope correction

The Pudding research is a separate public interactive-site benchmark, not evidence of Mews hospitality functionality. The verified Pudding findings were:

- Svelte/SvelteKit patterns and Vite/static builds.
- D3, Scrollama, Mapbox, LayerCake, and ArchieML documented or used in parts of the ecosystem, not proven on every story.
- S3/CloudFront-style hosting and GoatCounter observed.
- No claim that every story uses every named library.

Sources: [Pudding](https://pudding.cool/), [Scrollama process article](https://pudding.cool/process/introducing-scrollama/), [Pudding Svelte starter](https://github.com/the-pudding/svelte-starter), and [Pudding website repository](https://github.com/the-pudding/website).

## Verification caveat

The audit workers did not edit application files. The checkout was concurrently dirty from a separate PMS task, so test results are snapshots rather than a clean release baseline. The latest finance worker reported 46/46 focused finance tests, 210/210 API tests, and PHPStan 0; another concurrent snapshot reported 194/197 tests, 3 financial/projection failures, and 7 PHPStan errors while the shared checkout was changing.

## Current-catalog recheck result

The current Mews catalog and 2026 Mews OS launch material exposed additional explicit rows that were not in the first saved handoff: native Guest Messaging, the Mews Agent, Mews Automations, the native SiteMinder-powered Channel Manager, Mews OS as the umbrella layer, and ePOS as a separately marketed surface. Those rows are now appended to the relevant matrices.

## GitHub/OpenAPI re-audit result

The two newly supplied Mews research attachments were checked against the current first-party repository and live machine-readable contract. The pass found nine current Connector operations absent from the earlier saved matrix, one omitted Booking Engine operation (`Get promoted services`), and additional uncounted contracts for the Booking Engine widget/standalone integrations, Payments Checkout, the reverse Loyalty Partner API, POS JSON:API mechanics/use cases, Connector allowances, VAT-code handling, and detailed restriction behavior. The full feature-by-feature deltas and source links are in the [GitHub/OpenAPI re-audit](mews-vs-lodgeops-github-openapi-audit.md).

Third-party repositories such as `api-evangelist/mews`, the n8n node, and the MCP server were checked as implementation cross-checks. They are not treated as authoritative; the live Swagger and first-party Mews repository control the comparison. Cloudbeds and Apaleo links were checked as separate benchmark sources, not counted as Mews features.

This is the honest completeness boundary: the matrices cover the public first-party Mews catalog, current Mews OS announcements, official API indexes, help-center product surfaces, and Marketplace categories. They cannot prove private roadmap features, tenant-specific entitlements, gated rollouts, unpublished contracts, or every feature of every third-party Marketplace partner.

The decisive product conclusion is unchanged: LodgeOps has a real internal PMS/operations core, but the Mews parity gaps are concentrated in public booking/distribution, processor payments/tokenization/terminals, accounting/AR, RMS/dynamic pricing, hotel POS/ePOS, guest self-service/messaging/AI automation, Mews-compatible APIs/events, enterprise groups/chains, and the Marketplace ecosystem.
