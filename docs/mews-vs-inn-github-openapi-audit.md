# Mews vs Inn — GitHub/OpenAPI re-audit

Audit date: 2026-08-13.

This is the correction for the GitHub/API sources named in the attached Mews research. The first saved handoff did not fully inspect those links. This pass checks the active Mews documentation monorepo, the live Connector Swagger, the detailed API-space pages, and the implementation-oriented repositories from the research.

## Authority order

1. [Mews live Connector Swagger](https://api.mews.com/Swagger/connector/swagger.yaml) — current machine-readable contract.
2. [MewsSystems/open-api-docs](https://github.com/MewsSystems/open-api-docs) — first-party documentation source. The repository was checked at commit `75917e2` on 2026-08-13 and contains the Connector, Booking Engine, Channel Manager, POS, Loyalty Partner, Open API, and Payments Checkout spaces.
3. [Mews API documentation](https://docs.mews.com/) and current Mews product pages — human-readable behavior, rollout, and product scope.
4. Unofficial clients and mapping repositories — useful for implementation coverage and omissions, but not authoritative product contracts.

The public Mews GitHub organization does not expose the core PMS backend source. However, Mews separately publishes a current [Platform Documentation](https://www.mews.com/en/legal/platform-documentation) page that documents high-level production technology: Microsoft Azure; serverless multi-tenant SaaS; Azure App Service; Azure SQL Database; Azure Storage; Cosmos DB for non-business-critical data such as logs; C#/.NET backend; JavaScript/TypeScript and React frontend; Flutter and Kotlin mobile applications; LaunchDarkly for staged release targeting; PCI Proxy/Datatrans tokenization; and named subprocessors including Twilio, GoodData, Cloudflare, Confluent, Zuplo, and Google. These are platform-level disclosures, not a complete service-by-service architecture.

## Research-link inventory

| Research artifact | Checked | What it is useful for | Decision use |
|---|---:|---|---|
| [Mews Open API docs monorepo](https://github.com/MewsSystems/open-api-docs) | Yes | First-party API spaces and detailed operation/use-case docs | Primary |
| [Live Mews Connector Swagger](https://api.mews.com/Swagger/connector/swagger.yaml) | Yes | Current paths, operation IDs, request/response contract | Primary |
| [api-evangelist/mews](https://github.com/api-evangelist/mews) | Yes | 74 split OpenAPI files and 198 JSON examples for grep-able exploration | Cross-check only; split is behind the live 2026 contract in several areas |
| [Velocity-BPA/n8n-nodes-mews](https://github.com/Velocity-BPA/n8n-nodes-mews) | Yes | Working Connector client patterns: auth, cursor pagination, reservation/bill/payment/space actions | Implementation sample; BSL-licensed and incomplete |
| [code-rabi/mews-mcp](https://github.com/code-rabi/mews-mcp) | Yes | 54 implemented MCP tool declarations plus its backlog of unimplemented Mews operations | Coverage/backlog signal; unofficial |
| [cloudbeds/openapi-specs](https://github.com/cloudbeds/openapi-specs) | Yes | Separate Cloudbeds API benchmark | Not used as Mews evidence |
| [Cloudbeds operational report sample](https://github.com/cloudbeds/CBAPI-OperationalReportsClientApp) | Yes | Concrete Cloudbeds OAuth/API-key and reservation-report consumption pattern | Not used as Mews evidence |
| [api-evangelist/apaleo](https://github.com/api-evangelist/apaleo) and [apaleo API docs](https://apaleo.dev/guides/api/overview.html) | Yes | Adjacent API-first PMS comparison | Not used as Mews evidence |
| [Mews public code repositories](https://github.com/MewsSystems) | Yes | .NET, C#, Flutter/Dart, fiscalization, design-system, and sample-app signals | Stack signals only |
| [Mews Platform Documentation](https://www.mews.com/en/legal/platform-documentation) | Yes | Azure/serverless/multi-tenant architecture, C#/.NET, React/TypeScript, Flutter/Kotlin, Azure SQL/Storage/Cosmos DB, deployment/security/subprocessor details | High-level platform disclosure; not core source code or per-service topology |

## 1. Live Connector Swagger delta

The live Swagger currently parses as OpenAPI 3.0.4 with **205 paths and 205 operations**. The earlier protocol matrix had 196 Connector rows. The current first-party operations index has 197 linked Connector targets; the saved matrix was missing the customer-preauthorization target plus eight current live operations that are present in the detailed source files but not in that index.

These are the exact additions that must be considered in a feature-by-feature parity review:

| Current Mews operation | Exact endpoint | Mews contract detail | Inn status | Inn mismatch |
|---|---|---|---|---|
| Get all preauthorizations by customers | `POST /api/connector/v1/preauthorizations/getAllByCustomers` | Returns customer preauthorizations with card ID, amount/tax breakdown, reservation ID, state, code, customer ID, and active flag. [Source](https://github.com/MewsSystems/open-api-docs/blob/main/connector-api/operations/preauthorizations.md) | ABSENT | No preauthorization model, card reference, or provider operation. |
| Get current cancellation policies, version 2026-07-31 | `POST /api/connector/v1/cancellationPolicies/getAll/2026-07-31` | Current policy versions, including unassigned policies; supports policy IDs, updated interval, activity state, pagination, and portfolio access. Restricted/beta. [Source](https://github.com/MewsSystems/open-api-docs/blob/main/connector-api/operations/cancellationpolicies.md#get-all-cancellation-policies-ver-2026-07-31) | ABSENT | No cancellation-policy catalog or versioned policy retrieval. |
| Add cancellation policies | `POST /api/connector/v1/cancellationPolicies/add` | Creates enterprise policies with applicability, fee extents, offsets, absolute/relative fees, names, and external identifiers. Restricted/beta. [Source](https://github.com/MewsSystems/open-api-docs/blob/main/connector-api/operations/cancellationpolicies.md#add-cancellation-policies) | ABSENT | No cancellation-policy creation or fee-rule engine. |
| Update cancellation policies | `POST /api/connector/v1/cancellationPolicies/update` | Partial updates of policy fees, applicability, offsets, names, and external identifiers; portfolio-managed policies cannot be changed. Restricted/beta. [Source](https://github.com/MewsSystems/open-api-docs/blob/main/connector-api/operations/cancellationpolicies.md#update-cancellation-policies) | ABSENT | Reservation cancellation state is not a policy-management API. |
| Delete cancellation policies | `POST /api/connector/v1/cancellationPolicies/delete` | Deletes policies subject to portfolio and active-dependency rules. Restricted/beta. [Source](https://github.com/MewsSystems/open-api-docs/blob/main/connector-api/operations/cancellationpolicies.md#delete-cancellation-policies) | ABSENT | No policy deletion lifecycle. |
| Add resource categories | `POST /api/connector/v1/resourceCategories/add` | Creates space/room categories with service, type, capacity, extra capacity, localized names, classification, ordering, external ID, and accounting category. Restricted/beta. [Source](https://github.com/MewsSystems/open-api-docs/blob/main/connector-api/operations/resourcecategories.md#add-resource-categories) | ABSENT | Inn has resource/category concepts but no Mews Connector contract or category creation API. |
| Update resource categories | `POST /api/connector/v1/resourceCategories/update` | Partial updates to localized text, type, capacity, classification, ordering, external ID, and accounting category. Restricted/beta. [Source](https://github.com/MewsSystems/open-api-docs/blob/main/connector-api/operations/resourcecategories.md#update-resource-categories) | ABSENT | Internal resource edits are not Mews API-compatible category updates. |
| Delete resource categories | `POST /api/connector/v1/resourceCategories/delete` | Deletes categories only when active resources, reservations, mappings, restrictions, and related dependencies permit it. Restricted/beta. [Source](https://github.com/MewsSystems/open-api-docs/blob/main/connector-api/operations/resourcecategories.md#delete-resource-categories) | ABSENT | No dependency-aware category deletion. |
| Generate Guest Portal links | `POST /api/connector/v1/reservations/generateGuestPortalLinks` | Generates single-use, expiring links for Homepage, CheckIn, CheckOut, Chat, and Keys for a reservation/customer pair. Restricted/beta. [Source](https://github.com/MewsSystems/open-api-docs/blob/main/connector-api/operations/reservations.md#generate-guest-portal-links) | PARTIAL | Inn has a secure guest portal, but not Mews link types, expiry contract, single-use behavior, or Connector endpoint. |

The restrictions `add` and `delete` pages in the repository are explicitly discontinued; they are not counted as current live operations. The preferred current path is `set`/`clear` restrictions.

## 2. Booking Engine API and web integration surfaces

The active Booking Engine operation index has 15 entries. The saved protocol matrix had 14. The missing operation is:

| Mews operation | Exact source | Inn status |
|---|---|---|
| Get promoted services | [Booking Engine services operation](https://github.com/MewsSystems/open-api-docs/blob/main/booking-engine/booking-engine-api/operations/services.md#get-promoted-services) | ABSENT as an API contract; Inn has internal catalog/upsell concepts but no public Booking Engine operation returning promoted services, rates, resource categories, availability, and prices. |

The detailed web surfaces are also API-like contracts and were not individually enumerated in the earlier protocol matrix:

| Mews surface | Detailed capability | Inn status |
|---|---|---|
| Booking Engine Widget loader | CDN loader, `Mews.Distributor`, configuration IDs, `openElements`, iframe overlay, HTTPS-only behavior, CSP requirements, anti-cache rules | ABSENT |
| Widget JavaScript control API | `open`, `close`, language/currency/date/voucher/adult/child setters, tracking enable/disable | ABSENT |
| Widget booking modes | Single-enterprise rooms/rates and chain/multi-enterprise hotels/rooms/city selection | ABSENT |
| Widget payment integration | PCI Proxy card storage and Mews Payments gateway integration | ABSENT |
| Widget analytics integrations | Google Tag Manager, Universal Analytics, Google Ecommerce, Enhanced Ecommerce, consent-aware tracking | ABSENT |
| Booking Engine Standalone | Hosted `app.mews.com/distributor` page and configuration-ID-based multi-enterprise URLs | ABSENT |
| Standalone deeplinks | `mewsStart`, `mewsEnd`, `mewsVoucherCode`, `mewsRoute`, `mewsSort`, `mewsRoom`, adult/child counts, language, currency, city, and hotel parameters | ABSENT |

Sources: [Widget getting started](https://github.com/MewsSystems/open-api-docs/blob/main/booking-engine/booking-engine-widget/getting-started.md), [Widget reference](https://github.com/MewsSystems/open-api-docs/blob/main/booking-engine/booking-engine-widget/reference.md), and [Standalone deeplinks](https://github.com/MewsSystems/open-api-docs/blob/main/booking-engine/booking-engine-standalone/deeplinks.md).

## 3. Mews Payments Checkout SDK

The current first-party docs contain a separate embeddable JavaScript checkout, not just the Connector payment operations already listed in the finance matrix.

| SDK/API surface | Detailed capability | Inn status |
|---|---|---|
| Embedded loader | `https://cdn.mews.com/payments/checkout-embed.js`, responsive iframe, `Mews.PaymentCheckout.load()` | ABSENT |
| Flow 1 — payment request | Capture a pre-created Connector payment request; the checkout handles method selection, PCI card capture, 3DS, and posting the payment to Mews | ABSENT |
| Flow 2 — direct capture | Enterprise ID, amount, and currency are enough to collect payer details, create a guest account, collect a method, create the payment, and link it to the guest | ABSENT |
| Payment methods | Payment cards, Apple Pay, Google Pay, iDEAL, and SEPA Direct Debit | ABSENT |
| Future-charge method collection | Collect card or SEPA mandate with consent for later Operations, automation, or Connector charging | ABSENT |
| Success callbacks | `payment-charged`, `payment-submitted`, `payment-method-collected` | ABSENT |
| Failure callbacks | Payment and payment-method collection failure events with diagnostic error text | ABSENT |
| Checkout lifecycle | Reload by calling `load` with a new request, `destroy` to remove the iframe | ABSENT |
| Redirect and security behavior | iDEAL redirect, Apple Pay domain activation, CSP allow-list, PCI Proxy domain, demo environment | ABSENT |
| Configuration controls | Request/context, callbacks, tracking, payment ID, enabled methods, language, currency, multicurrency, payer/billing prefill | ABSENT |
| Theme controls | Global tokens, payment-method selection, buttons, inputs, spinner, info/status cards; no layout-engine override | ABSENT |

Source: [Mews Payments Checkout](https://github.com/MewsSystems/open-api-docs/blob/main/mews-payments-checkout/README.md). The existing finance rows correctly mark the payment methods and provider execution as absent; this section adds the missing SDK contract and flows.

## 4. Loyalty Partner API

This is not a normal outbound Connector API. It is a reverse API: the loyalty provider exposes endpoints and Mews calls them from Mews Operations.

| Partner surface | Detailed contract/workflow | Inn status |
|---|---|---|
| Reverse API deployment | Partner supplies demo and production base URLs; Mews calls the partner API | ABSENT |
| Authentication | Bearer token supplied by the partner; one token covers the integration; current docs describe one-year tokens and manual rotation accepting both tokens during rollover | ABSENT |
| Search members | Mews searches partner members using customer identity data such as name, email, phone, and address | ABSENT |
| Enroll customer | Front desk enrolls a Mews customer in a selected partner loyalty program | ABSENT |
| Link membership | Staff links an existing partner membership to a Mews customer | ABSENT |
| Unlink membership | Staff removes the membership link without deleting the partner member | ABSENT |
| List/refresh memberships | Manual refresh plus daily automatic synchronization for customers arriving the next day | ABSENT |
| Checkout notifications | Optional checkout event handling for partner use cases | ABSENT |
| Certification and pilot | Mews demo property, joint certification review, then first-property/chain pilot | ABSENT |

Sources: [Loyalty Partner overview](https://github.com/MewsSystems/open-api-docs/blob/main/loyalty-partner/README.md), [Getting started](https://github.com/MewsSystems/open-api-docs/blob/main/loyalty-partner/getting-started.md), and [workflow index](https://github.com/MewsSystems/open-api-docs/blob/main/loyalty-partner/workflows/README.md).

The Connector API's separate loyalty operations were already counted in the protocol matrix. The Partner API is an additional integration direction and must not be conflated with those operations.

## 5. POS API details and use cases

The earlier protocol matrix counted POS operation rows and POS webhooks, but the current GitHub docs add important implementation behavior and restricted use cases:

| POS surface | Detailed capability | Inn status |
|---|---|---|
| JSON:API request/response contract | `application/vnd.api+json`, resource objects, attributes, relationships, included resources, JSON:API errors | ABSENT |
| Relationships and includes | `include=...` loads linked tables, customers, invoices, registers, order items, and related resources | ABSENT |
| Sparse fieldsets | `fields[TYPE]=...` retrieves only requested attributes | ABSENT |
| Filtering | Date, state, table, register, customer, and operation-specific filters | ABSENT |
| Cursor pagination | JSON:API `page[after]`, `page[before]`, and `page[size]` links | ABSENT |
| Idempotency | `Idempotency-Key` for supported state-changing operations, including payment safety and retry behavior | ABSENT |
| Digital ordering | Restricted partner use case: browse catalog, poll product/order changes, create/manage orders, process payments, and validate room-charge rules | ABSENT |
| Restaurant table booking | Check availability, create/amend/cancel bookings, link customers/tables, walk-ins, status events, and customer spend through order/invoice relationships | ABSENT |
| POS inventory synchronization | Product/catalog sync plus invoice sales extraction with tax, discount, tip, register, and original-invoice/refund relationships | ABSENT |
| POS event delivery | Webhooks for booking/order/product/payment changes with HMAC signature validation | ABSENT |

Sources: [POS request guidelines](https://github.com/MewsSystems/open-api-docs/blob/main/pos-api/guidelines/requests.md), [relationships](https://github.com/MewsSystems/open-api-docs/blob/main/pos-api/guidelines/relationships.md), [sparse fieldsets](https://github.com/MewsSystems/open-api-docs/blob/main/pos-api/guidelines/sparse-fieldsets.md), [idempotency](https://github.com/MewsSystems/open-api-docs/blob/main/pos-api/guidelines/idempotency.md), [digital ordering](https://github.com/MewsSystems/open-api-docs/blob/main/pos-api/use-cases/digital-ordering.md), [table booking](https://github.com/MewsSystems/open-api-docs/blob/main/pos-api/use-cases/table-booking.md), and [inventory](https://github.com/MewsSystems/open-api-docs/blob/main/pos-api/use-cases/inventory.md).

## 6. Connector use cases that sharpen the feature comparison

The current Connector use-case index includes Accounting, Allowances, Customer loyalty, Customer management, Events, Guest technology, Housekeeping, Kiosk, Point of sale, Reputation management, Revenue management, Upsell, Data export, Device integration, Customer messaging, Mews Payment Terminals, and Payment automation. These are usage patterns over the operations/events, not additional REST paths, but they add real feature behavior.

The most material previously unscored use case is Allowances:

| Allowance behavior | Inn status | Mismatch |
|---|---|---|
| Allowance product as a bill liability | ABSENT | No allowance product/order-item discriminator. |
| Automatic discount by permitted accounting category | ABSENT | No category-matched automatic discounting. |
| Partial consumption capped at remaining balance | ABSENT | No allowance balance or capped offset. |
| Breakage and contra-breakage at checkout/expiry | ABSENT | No breakage, contra, or loss accounting items. |
| Link charge to reservation with `LinkedReservationId` | PARTIAL | Reservation-linked retail posting exists, but not allowance semantics. |
| Retrieval and reconciliation by allowance order-item types | ABSENT | No allowance-specific order-item query or reconciliation. |
| VAT code workflow | PARTIAL | Tax arithmetic exists, but not Mews TaxEnvironment/Taxation/TaxRate lookup and code propagation. |
| Restriction management by rate/resource and condition/exception | ABSENT | Existing rows cover the operations, but this detailed mapping/use-case behavior is not implemented. |

Sources: [Allowances](https://github.com/MewsSystems/open-api-docs/blob/main/connector-api/use-cases/allowances.md), [VAT codes](https://github.com/MewsSystems/open-api-docs/blob/main/connector-api/additional-functions/vat-codes.md), and [restriction management](https://github.com/MewsSystems/open-api-docs/blob/main/connector-api/additional-functions/manage-restrictions.md).

## 7. Public Mews code repositories: stack signals only

| Repository | Verified public signal | What it does not prove |
|---|---|---|
| [MewsSystems/fiscalizations](https://github.com/MewsSystems/fiscalizations) | C#/.NET library, production note for .NET 8, async I/O, immutable DTOs, functional patterns, country fiscalization packages, CI/CD | Not the Mews PMS backend or a complete platform architecture diagram |
| [MewsSystems/FuncSharp](https://github.com/MewsSystems/FuncSharp) | C# functional-programming library used by public Mews code | Not evidence that every Mews service uses it |
| [MewsSystems/mews-flutter](https://github.com/MewsSystems/mews-flutter) | Dart/Flutter packages, Optimus design system, kiosk-mode plugin | Mobile/design-system packages only |
| [MewsSystems/reservations-interview](https://github.com/MewsSystems/reservations-interview) | Interview sample using .NET 8, SQLite, React, TypeScript, Rspack/Rsbuild-style tooling, and TanStack libraries | Explicitly a sample/interview application, not production Mews stack |
| [MewsSystems/open-api-docs](https://github.com/MewsSystems/open-api-docs) | GitBook documentation monorepo; current API spaces and generated Connector docs | Documentation source, not core application source |
| [Mews Platform Documentation](https://www.mews.com/en/legal/platform-documentation) | Azure App Service, Azure SQL Database, Azure Storage, Cosmos DB logs, C#/.NET backend, React/TypeScript frontend, Flutter/Kotlin mobile, LaunchDarkly release targeting, PCI Proxy/Datatrans, and named cloud subprocessors | High-level platform facts; it does not map each product to a separate service, repo, queue, or database |

## 8. Final completeness boundary after this re-audit

The Mews comparison now has direct coverage of:

- The live 205-operation Connector contract and its current delta.
- The full active Booking Engine API index plus widget and standalone controls.
- Connector, Booking Engine, Channel Manager, and POS protocol mechanics.
- POS JSON:API behavior, idempotency, filtering, sparse fieldsets, relationships, and use cases.
- Mews Payments Checkout SDK flows and configuration.
- Loyalty Partner reverse API workflows and certification.
- Connector allowances, VAT-code, and restriction-management behavior.
- Public Mews code-repository stack signals, kept separate from proven product capabilities.

The Cloudbeds and Apaleo repositories in the research were also checked, but they remain separate benchmark sources and are not silently counted as Mews features. No public Mews core PMS source repository was found in the checked organization; the high-level production stack is nevertheless documented in the official Platform Documentation page above.
