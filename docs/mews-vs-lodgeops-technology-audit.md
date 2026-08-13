# Mews vs LodgeOps — up-to-date technology-by-feature audit

Audit date: 2026-08-13.

This companion audit records the public technology evidence for each Mews feature surface, then compares it with the implementation actually present in LodgeOps.

## Evidence rules

- **VERIFIED CONTRACT** — named in an Mews API or help document: protocol, SDK, browser runtime, device platform, security mechanism, or provider.
- **DOCUMENTED PLATFORM** — named in Mews’ official Platform Documentation.
- **PUBLIC CODE SIGNAL** — present in a public Mews repository, but not necessarily in the corresponding production service.
- **UNKNOWN** — not publicly disclosed; no private architecture is inferred from marketing or sample repositories.

## Current source snapshots

| Source | Snapshot | What it verifies |
|---|---|---|
| [Mews Platform Documentation](https://www.mews.com/en/legal/platform-documentation) | Current page checked 2026-08-13 | Azure, serverless multi-tenant SaaS, Azure App Service, Azure SQL, Azure Storage, Cosmos DB, C#/.NET, React/TypeScript, Flutter/Kotlin, LaunchDarkly, security, and subprocessors |
| [Mews Open API docs](https://github.com/MewsSystems/open-api-docs) | First-party HEAD 75917e2 checked 2026-08-13 | GitBook, OpenAPI/Swagger references, Node generator, Connector, Booking Engine, Channel Manager, POS, Payments Checkout, Loyalty Partner |
| [Live Connector Swagger](https://api.mews.com/Swagger/connector/swagger.yaml) | Parsed 2026-08-13 | OpenAPI 3.0.4; 205 paths and 205 operations |
| [Mews POS OpenAPI](https://api.mews.com/pos/v1/docs/openapi.yaml) | Linked by current first-party POS docs | Current POS schema/operation contract; POS docs label the API active development |
| [Mews fiscalizations](https://github.com/MewsSystems/fiscalizations) | HEAD 8c55dcd, 2026-06-26 | C#/.NET fiscalization, async I/O, immutable DTOs, FuncSharp, NuGet, country packages, CI |
| [Mews Flutter](https://github.com/MewsSystems/mews-flutter) | HEAD 2e3bcb1, 2026-07-29 | Flutter/Dart, Optimus, kiosk-mode, remote logger |
| [FuncSharp](https://github.com/MewsSystems/FuncSharp) | HEAD cfca562; .NET 8/.NET 10 targets | Public Mews C# functional library |
| [n8n Mews node](https://github.com/Velocity-BPA/n8n-nodes-mews) | Unofficial HEAD 36c39f8, 2026-04-25 | TypeScript/Node/n8n Connector client pattern |
| [Mews MCP](https://github.com/code-rabi/mews-mcp) | Unofficial HEAD 8880d13, 2025-08-06 | TypeScript/Node MCP client pattern |
| [Reservations interview sample](https://github.com/MewsSystems/reservations-interview) | HEAD 20e1897, 2024-12-11 | .NET 8, SQLite, Dapper, Swashbuckle, React, TypeScript, Rsbuild, TanStack; sample only |

## Mews platform stack explicitly disclosed

| Layer | Technology | Confidence |
|---|---|---|
| Cloud | Microsoft Azure; cloud-native, serverless, multi-tenant SaaS | DOCUMENTED PLATFORM |
| Hosting | Azure App Service; independently deployed Commander, Distributor, Navigator web applications | DOCUMENTED PLATFORM |
| Backend | C# and .NET | DOCUMENTED PLATFORM |
| Web frontend | JavaScript/TypeScript and React | DOCUMENTED PLATFORM |
| Mobile | Flutter and Kotlin | DOCUMENTED PLATFORM |
| Business data | Premium Azure SQL Database; primary high-availability cluster in Germany West Central with replicas in North Europe | DOCUMENTED PLATFORM |
| Binary data | Azure Storage with geo-redundancy and soft delete | DOCUMENTED PLATFORM |
| Logs/non-critical data | Azure Cosmos DB, replicated across regions | DOCUMENTED PLATFORM |
| Release targeting | LaunchDarkly for internal alpha, private beta, and public beta | DOCUMENTED PLATFORM |
| Security | TLS 1.2+, encryption at rest, audit logs, MFA, bcrypt, least privilege, Azure Security Advisor, continuous penetration testing | DOCUMENTED PLATFORM |
| Card security | PCI Proxy tokenization; raw card data does not reach Mews; Datatrans is the named tokenization subprocessor | DOCUMENTED PLATFORM |
| Named platform subprocessors | Twilio messaging, GoodData BI, Cloudflare CDN, Confluent streaming, Zuplo API gateway, Okta IAM, Google push, Salesforce support | DOCUMENTED PLATFORM |

The platform page does not publish the core PMS repositories, per-feature service boundaries, queue/cache/event-bus topology, AI providers, or complete internal frontend architecture.

## Technology by feature

### PMS, reservations, front desk, and operations

| Mews feature | Mews technology evidence | LodgeOps comparison |
|---|---|---|
| PMS/Operations core | React/TypeScript web, C#/.NET backend, Azure serverless SaaS; internal service topology unknown | Laravel 13/PHP 8.3, Filament 5; internal analogue only |
| Reservations/service orders | Connector HTTPS POST, JSON bodies, OpenAPI/Swagger, UTC serialization, cursor pagination | Laravel models/controllers/services; no Mews Connector client |
| Group reservations | Connector reservation groups, Booking Engine group operations, Channel Manager async group processing over HTTPS POST JSON | Internal reservations; no Mews group/distribution contract |
| Guests/customers | Connector customer/account JSON contracts; Webhook entity IDs; identity, address, preference, consent fields | Laravel Guest model/merge service; no Mews schema compatibility |
| Companies/contracts/notes | Connector HTTPS JSON resources for companies, contracts, departments, account notes | Laravel organizations/notes; no Mews company-contract API |
| Resources/spaces/categories | Connector resource/category/feature operations; resource states; Webhooks/WebSockets for updates | Laravel Resource/Category/Allocation services; no Mews contract |
| Availability/occupancy | Connector availability, adjustments, blocks, occupancy, pricing; Booking Engine availability | Laravel availability/projections; no distribution adapter |
| Rates/rate groups | Connector rate/price operations; PriceUpdate WebSocket event; pricing engine private | Laravel program/proposal prices; no rate-plan publisher |
| Restrictions/cancellation policy | Connector set/clear restrictions and current versioned cancellation-policy CRUD; JSON/OpenAPI | Date validation only; no Mews rule/policy engine |
| Housekeeping/tasks | Resource state, tasks, departments, blocks, Webhooks/WebSockets; Flexkeeping is a separate product | Laravel housekeeping, tasks, queue/outbox; no external adapter |
| Services/products/upsells | Connector products/services/orders; Booking Engine services availability/pricing/promoted services; kiosk/portal clients | Laravel catalog, service occurrences, retail, folios; no public booking upsell contract |
| Bills/accounting/order items | Connector bill/order/payment/accounting/tax APIs with JSON, UTC, pagination, events | Laravel folios/deposits/manual payments; no Mews ledger compatibility |
| Devices | Connector printer, key-cutter, payment, fiscal-machine commands; hardware SDK/protocol unknown | No device model or command adapter |
| Events/MICE | API primitives for blocks, groups, companies, spaces, orders, bills, tasks, Webhooks/WebSockets | Laravel programs/proposals; no MICE function-sheet/quote stack |

### Booking Engine, guest journey, and self-service

| Mews feature | Mews technology evidence | LodgeOps comparison |
|---|---|---|
| Booking Engine API | HTTPS POST at api/distributor/v1, registered Client, JSON, demo/production environments | No public booking engine/distributor client |
| Booking Engine Widget | CDN JavaScript loader at api.mews.com/distributor/distributor.min.js; global Mews.Distributor; async browser initialization | Next.js/React public site; no widget |
| Widget security | Iframe overlay, HTTPS requirement, CSP allowlist for Mews/reCAPTCHA/PCI Proxy; loader must not be bundled or cached | No iframe booking/CSP integration |
| Widget browser API | JavaScript open/close, date/language/currency/voucher/occupancy, room/rate/hotel navigation, tracking consent | No equivalent API |
| Widget analytics | Google Tag Manager, GA4 custom events/data layer, cross-domain tracking, consent controls; Universal Analytics removed from current docs | No GTM/GA4/data-layer/consent integration |
| Standalone booking | Hosted distributor route and date/voucher/room/route/occupancy/language/currency/city/hotel deeplinks | No hosted distributor/deeplink parser |
| Booking card entry | PCI Proxy Secure Fields; DataTrans secure-fields domain; transaction ID becomes payment gateway data | No gateway/card field/tokenization flow |
| Guest Portal | Web/mobile/tablet links for online check-in/out, identity, products, upgrades, registration, bills, payments, messaging; framework private | Laravel portal and one-time tokens; no Mews journey contract |
| Online check-in/out | Browser flow, identity scan/photo, digital cards, upgrades, payments, bill settlement, automatic bill email | Laravel documents/folios; no scanner/OCR/provider |
| Kiosk | Native app on iPadOS 13+ and Android 7+ tablets; camera, QR provisioning, guided access/app lock, terminal/QR payments, key cutter, identity scan/photo | No kiosk app/device policy/key cutter/terminal |
| Bluetooth Digital Key | Smartphone app and Bluetooth; Assa Abloy Vingcard Vostio/Visionline and Salto Space locks | No mobile key/lock adapter |
| Wallet Digital Key | Apple Wallet iPhone/Apple Watch; Google Wallet Android; Android guidance names Android 9+ with NFC | No wallet pass/NFC/lock provisioning |
| Guest Messaging | Portal messaging and current Mews OS unified center across email, SMS, WhatsApp, OTA, web; Twilio named for mailing/text; broker/AI runtime private | Laravel email/templates/outbox only |
| Mews Agent/Automations | Current product material describes autonomous conversations and template/natural-language workflows; model/provider/orchestration unknown | Laravel rules/queues; no agent or visual builder |
| Loyalty Partner | Reverse HTTPS API; partner implements Mews OpenAPI; bearer auth, JSON body, manual token rotation, optional checkout events | No partner-hosted loyalty API/certification |

### Payments, finance, and revenue

| Mews feature | Mews technology evidence | LodgeOps comparison |
|---|---|---|
| Payments Checkout | JavaScript SDK at cdn.mews.com/payments/checkout-embed.js; Mews.PaymentCheckout; responsive iframe; load/destroy/callbacks | No checkout SDK/processor adapter |
| Checkout flows | Connector payment/payment-method requests or direct enterprise/amount/currency context; card capture, 3DS, posting to Mews | Deposits/manual payments only |
| Payment methods | Cards, Apple Pay, Google Pay, iDEAL, SEPA Direct Debit; future card/SEPA mandate collection | No processor, wallet, SEPA, mandate, 3DS |
| Tokenization | PCI Proxy/DataTrans; raw PAN/CVV stays outside Mews; token stored and later detokenized through PCI Proxy | No PCI boundary/vault |
| Preauthorizations | Connector JSON data contract with card reference, tax/amount breakdown, state, reservation/customer links; acquirer private | No preauth/card-reference model |
| Payment automation/terminals | Automatic payment plans, later card/SEPA charges, Connector terminal/device commands, Kiosk pairing; scheduler/hardware SDK private | Internal automation only; no charge execution/terminal |
| AR/invoices/reconciliation | Product material documents invoice-to-cash, AR, automated reconciliation; ledger, bank feed, scheduler, matching engine private | Folios/projections/CSV only |
| Tax/fiscalization | Public C#/.NET fiscalization library: async I/O, immutable DTOs, FuncSharp, NuGet country packages, Windows/Linux CI; current workflows also target .NET 10 | Tax arithmetic; no government integration |
| Multicurrency/FX | Connector currencies/exchange rates and payment currency; Checkout currency/multicurrency; FX source private | Laravel FX service/snapshots; no Mews payment compatibility |
| RMS/dynamic pricing/forecasting | Public product docs describe demand response, pickup, seasonality, cancellations, competitor signals, approval/autopilot; model/data pipeline private | No RMS/rate publisher/forecast model |
| BI/reporting | Curated dashboards, filters, data model, two-hour refresh, CSV/Excel/PDF/PowerPoint/scheduled delivery; warehouse/query/generator private | Filament widgets, Laravel projections, CSV utility only |
| AI BI | Current product material describes AI performance insight; model/provider/retrieval unknown | No AI BI |

### APIs, integrations, POS, and distribution

| Mews feature | Mews technology evidence | LodgeOps comparison |
|---|---|---|
| Connector transport/auth | HTTPS POST, JSON, ClientToken + AccessToken + Client, UTC, demo/production, OpenAPI/Swagger | Laravel internal REST/Sanctum; no external client |
| Connector resilience | Cursor/count limits, HTTP 429, Retry-After, exponential backoff, caching, circuit breakers, graceful degradation | Internal queues/idempotency; no Mews retry client |
| Connector Webhooks | Partner HTTP POST JSON endpoint, shared secret URL token, timely response, retries; general/integration events | No inbound Mews webhook consumer |
| Connector WebSockets | WSS separate host at /ws/connector, tokens in cookies; Reservation/Resource/PriceUpdate/DeviceCommand events | No WSS client/server |
| Channel Manager | HTTPS POST JSON, TLS 1.2+, Client Token + property Connection Token, async confirmations, demo certification | No ARI/channel mapping/sync |
| POS API | REST + JSON:API media type application/vnd.api+json, bearer API key, OpenAPI, relationships/includes, sparse fieldsets, filters, cursor links | Laravel JSON resources; no JSON:API POS |
| POS idempotency/webhooks | Idempotency-Key; managed webhook endpoints; X-Signature HMAC-SHA256 over raw body with Base64 | Internal idempotency only; no POS webhook/HMAC |
| POS use cases | Digital ordering, table bookings, inventory synchronization, orders, invoices, payments, room-charge validation | Retail sales/tasks only |
| Marketplace | Public catalog and partner onboarding/certification; provider may use Connector or another API; catalog backend private | Generic integration configuration only |

## Public-code signals, not production proof

| Repository | Technology explicitly present | Safe conclusion |
|---|---|---|
| [Mews Open API docs](https://github.com/MewsSystems/open-api-docs) | GitBook; Node/npm generator; edge.js, oas, oas-normalize, yaml; GitHub Actions | API documentation generation/maintenance |
| [Mews fiscalizations](https://github.com/MewsSystems/fiscalizations) | C#/.NET 8 and current .NET 10 workflows; FuncSharp; immutable DTOs; NuGet; xUnit; CodeQL | Fiscalization component signal |
| [Mews Flutter](https://github.com/MewsSystems/mews-flutter) | Dart/Flutter, Melos, Optimus, kiosk_mode, remote_logger | Mobile/design-system signal |
| [FuncSharp](https://github.com/MewsSystems/FuncSharp) | C# functional ADTs, Option/Try, DataCube, interval types; .NET 8/.NET 10; xUnit/FsCheck | Reusable public Mews C# library |
| [Reservations interview](https://github.com/MewsSystems/reservations-interview) | .NET 8, Dapper, SQLite, Swashbuckle; React 19, TypeScript, Rsbuild, TanStack, Radix, styled-components, Zod | Sample architecture only |
| [n8n Mews node](https://github.com/Velocity-BPA/n8n-nodes-mews) | TypeScript/Node/n8n, POST JSON, Connector auth/cursor patterns | Unofficial client only |
| [Mews MCP](https://github.com/code-rabi/mews-mcp) | TypeScript/Node, npm/npx MCP server, Connector credentials | Unofficial partial client only |

## LodgeOps technology baseline

| LodgeOps surface | Verified local technology | Gap |
|---|---|---|
| Staff/PMS app | PHP 8.3, Laravel 13, Filament 5, Sanctum, Eloquent, queues/scheduler | No Mews Azure/C#/.NET/React compatibility or token contract |
| Public web | Next.js 16, React 19.2, TypeScript, Playwright | No Distributor widget/booking engine/CSP/PCI Proxy/GA4-GTM contract |
| Reservations/resources/guests | Laravel models/controllers/services, tenant scopes, internal JSON resources | No Connector/OpenAPI schema, cursor/auth/event implementation |
| Guest portal | Laravel portal, one-time tokens, documents/acknowledgements, email delivery | No Mews link types, scanner, payment, key, or messaging integration |
| Automation | Laravel rules, queued jobs, outbox, scheduler | No Mews Webhooks/WSS adapter, Agent, or Automations |
| Finance | Laravel folios, deposits, manual payments, FX snapshots, projections, CSV | No processor, PCI Proxy, 3DS, wallets, SEPA, terminals, AR, reconciliation, fiscalization, or BI export engine |
| Integrations | Integration configuration model/service only | ADAPTER-ONLY; no provider SDK or Mews certification/execution |

## Still unknown

- Mews Operations frontend component framework beyond the platform-level React disclosure.
- Per-feature backend services, repositories, queues, caches, event bus, and schemas.
- Exact mobile framework used by each production Kiosk, Digital Key, Guest Portal, and Operations client.
- Payment-acquirer orchestration beyond PCI Proxy/Datatrans.
- AI model/provider, vector store, retrieval/orchestration, and safety controls.
- Internal AR, reconciliation, RMS, dynamic-pricing, BI-refresh, Marketplace, and Channel Manager implementation.

## Bottom line

The up-to-date public Mews technology picture is: Azure serverless multi-tenant SaaS; C#/.NET backend; JavaScript/TypeScript and React web; Flutter and Kotlin mobile; Azure SQL/Storage/Cosmos DB; LaunchDarkly; PCI Proxy/Datatrans tokenization; Twilio/GoodData/Cloudflare/Confluent/Zuplo and other named subprocessors; OpenAPI/Swagger; HTTPS JSON; JavaScript iframe/CDN integrations; 3DS; iOS/Android, Bluetooth, Apple Wallet, Google Wallet, NFC, QR, terminals and key cutters; JSON:API POS; bearer/client/connection tokens; HTTP Webhooks; HMAC-SHA256; and WSS WebSockets.

This is a technology audit at the highest public confidence available. Private implementation details remain UNKNOWN rather than being invented.
