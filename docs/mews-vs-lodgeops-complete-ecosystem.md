<!-- Generated from the completed row-level audit worker output. Statuses and claims are preserved verbatim from the worker; verify live Mews URLs before using this as a product contract. -->

Technology by feature is tracked in the [up-to-date technology audit](mews-vs-lodgeops-technology-audit.md).

# Worker D audit — Mews ecosystem, security, API, Groups & Chains, and public website

Audit date: August 13, 2026. I cross-checked the live Mews Marketplace/site, official Mews help/docs, and the current LodgeOps checkout.

Status:

- **Executable:** LodgeOps has a real working implementation.
- **Partial:** LodgeOps has an internal analogue, but not Mews-equivalent functionality.
- **Adapter-only:** Configuration/storage exists, but no provider execution.
- **Absent:** No concrete implementation found.
- **Unverified:** Public evidence does not establish the claim.

LodgeOps’ generic integration records are not counted as integrations unless there is a provider client and execution path.

## 1. Mews Marketplace categories and current counts

The live Mews Marketplace advertises **1,000+ integrations** and currently exposes 60 Marketplace pages. Category counts are a live snapshot and can change. The current page showed 25 categories; HR & Staffing was present but its count was not rendered. Source: [Mews Marketplace](https://www.mews.com/en/products/marketplace).

| Mews Marketplace category | Current count | Representative capability | LodgeOps status | LodgeOps evidence |
|---|---:|---|---|---|
| Access Management | 3 | Smart locks, remote access, key systems | Absent | No provider client in [composer.json](/Users/ceron/Developer/Projects/lodge-ops/apps/api/composer.json:8) |
| Accounting | 38 | Accounting exports, ledgers, reconciliation | Adapter-only | Generic type only in [IntegrationConnection.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Models/IntegrationConnection.php:7) |
| Analytics / Business Intelligence | 3 | External BI and analytics tools | Partial internally; no external adapter | Internal finance endpoint in [api.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/routes/api.php:49) |
| Artificial Intelligence | 3 | AI guest, finance, or operations tools | Absent | No AI provider dependency or execution path |
| Business intelligence | 22 | Dashboards, chain reporting, KPI analysis | Partial internally | Internal finance/operations projections only |
| Channel Management | 25 | OTA reservations, rates, availability, restrictions | Absent | No channel-manager client or inbound reservation route |
| Customer management | 22 | CRM, guest data, reputation/customer tools | Partial internally; no external connector | Organizations/opportunities only in [api.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/routes/api.php:96) |
| Distribution | 81 | Direct and indirect room distribution | Absent | No OTA/distribution implementation |
| Event management | 19 | MICE, group events, event bookings | Partial | Proposals/programs exist, but no event-management integration |
| Facility management | 53 | Housekeeping, access, devices, maintenance | Partial internally; no external adapters | Housekeeping/resource workflows only |
| Food & Beverage | 78 | Restaurant, bar, outlet, kitchen integrations | Partial retail only | Retail sale route in [api.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/routes/api.php:91) |
| Guest Experiences | 180 | Digital forms, ID verification, guest journey | Partial | Guest portal exists; no ID provider or online check-in engine |
| Guest technology | 69 | Guest apps, concierge, Wi-Fi, room controls | Partial portal only | Guest portal routes in [api.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/routes/api.php:36) |
| HR & Staffing | Not rendered | Workforce, staffing, payroll tools | Partial internally | Roles/tasks exist; no external HR adapter |
| Integration management | 12 | Marketplace connection/subscription tools | Adapter-only | Configuration UI only in [IntegrationConnectionResource.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Filament/Resources/IntegrationConnections/IntegrationConnectionResource.php:24) |
| Legal environment | 21 | Regulatory reporting and country-specific compliance | Absent | No regulatory connector |
| Loyalty | 1 | Loyalty programs and memberships | Absent | No loyalty model/service |
| Marketing | 6 | Campaigns, CRM marketing, direct-booking marketing | Partial internally | CRM/email only; no marketing provider |
| Operations | 114 | Mobile operations, task and workflow tools | Partial internally | Internal task/operations routes |
| Payments | 7 | Payment processors and payment services | Absent | Manual ledger only in [PaymentService.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Services/PaymentService.php:23) |
| Point of sale | 21 | Restaurant POS, registers, orders, menus | Partial retail only | Catalog/retail routes, no hotel POS |
| Reputation management | 10 | Tripadvisor, Revinate, review platforms | Absent | No reputation connector |
| Revenue management | 145 | RMS, dynamic pricing, forecasting | Absent | No RMS or pricing engine |
| Spa management | 15 | Spa bookings, treatments, appointments | Absent | No spa model or connector |
| Upselling | 8 | Booking, pre-arrival, in-stay upsells | Partial staff catalog only | No guest-facing upsell flow |

## 2. Representative Marketplace integration classes

Examples below are visible in the current Marketplace directory. Source: [Mews Marketplace](https://www.mews.com/en/products/marketplace).

| Integration class | Mews examples | LodgeOps status | LodgeOps evidence |
|---|---|---|---|
| OTA reservation distribution | Expedia, Booking.com | Absent | No OTA client or reservation ingestion |
| Channel manager | SiteMinder | Absent | No channel-manager API |
| Rate distribution | SiteMinder and channel managers | Absent | No rate-push implementation |
| Availability distribution | SiteMinder and channel managers | Absent | No inventory-push implementation |
| Restriction distribution | Channel-manager integrations | Absent | No restriction engine |
| Smart locks | RemoteLock, Salto, Vostio, Hotek | Absent | No lock SDK/provider |
| Key cutters | Mews Connector-compatible devices | Absent | No device-command model |
| Digital keys | Bluetooth, Apple Wallet, Google Wallet | Absent | No digital-key or door-lock flow |
| Identity verification | 365id Scanner, Chekin | Absent | Guest documents are not identity verification |
| Accounting exports | Acomba, Avantage, Sage, Abacus | Adapter-only | Generic accounting configuration only |
| External BI | Power BI-backed tools, profitize, Abal | Partial internally | Internal projections; no external sync |
| Revenue management | Atomize, BEONX, Duetto | Absent | No RMS adapter or rate publisher |
| AI finance | profitize | Absent | No AI finance service |
| Payment processors | Mews Payments and processor integrations | Absent | No processor SDK/client |
| Payment terminals | Mews Terminal A1/A4/S2 | Absent | No terminal/device commands |
| Restaurant POS | Booq, Clyo, Addipos, Epicuri | Partial retail only | Retail sales are not restaurant POS |
| F&B revenue sync | 20Tabs and related F&B tools | Partial | Catalog/stock exists; no outlet/order system |
| Guest messaging | Aara, Akia, Duve | Partial email/portal only | Non-email channels throw an adapter error in [CommunicationDeliveryService.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Services/CommunicationDeliveryService.php:30) |
| WhatsApp concierge | Aara | Absent | No WhatsApp provider |
| Guest apps | Bowo, Alliants, Duve | Partial portal only | No native guest app or concierge |
| Wi-Fi/building control | 4WiFi | Absent | No Wi-Fi, HVAC, TV, or building-control integration |
| Loyalty | Stash Hotel Rewards | Absent | No loyalty data model |
| Reputation | Tripadvisor, Revinate, Customer Alliance | Absent | No review/reputation connector |
| Spa | Spalopia | Absent | No spa scheduling domain |
| Housekeeping | Abitari, 1CHECK | Partial internally | Internal housekeeping only |
| Maintenance/facilities | Facility-management apps | Partial internally | No maintenance/work-order integration |
| Events/MICE | Event Temple and similar tools | Partial | Proposals exist; no event platform |
| Regulatory reporting | 7H NTAK Connector | Absent | No country-specific regulatory export |
| HR/staffing | Workforce and payroll tools | Partial internally | Roles/tasks exist; no HR provider |
| Marketing | CRM and campaign tools | Partial internally | CRM/email only |
| Guest upselling | Pre-arrival and in-stay upsell apps | Partial | Staff catalog only |
| Marketplace administration | Connect, disable, disconnect, subscriptions | Adapter-only | LodgeOps has configure/list only |

Mews distinguishes Mews-owned add-ons from third-party integrations. Source: [Marketplace Terms](https://www.mews.com/en/legal/marketplace-terms).

## 3. First-party Mews products and add-ons

Mews defines a “Mews Add-On” as a Mews-provided service or integration sold through the Marketplace or subscription system. Source: [Marketplace Terms](https://www.mews.com/en/legal/marketplace-terms).

| Mews first-party product/add-on | Mews capability | LodgeOps status | LodgeOps evidence |
|---|---|---|---|
| Mews Analytics | Embedded Power BI dashboards, chain/property analytics, pickup and financial reporting | Partial internally; Mews capability absent | Internal projections only |
| Mews Multi-Property | Central portfolio management, bulk operations, unified property/guest data | Partial | Tenant/property model in [Tenant.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Models/Tenant.php:26) |
| Advanced Guest Experience | AI Smart Tips, richer guest journey and forms | Partial guest portal only | Guest portal lacks AI and self-service breadth |
| Mews Kiosk | Self-service arrival and key issuance | Absent | No kiosk frontend or device integration |
| Mews Digital Key | Bluetooth/mobile/wallet key after online check-in | Absent | No lock/key integration |
| Text Messaging Package | Mews SMS guest communications | Absent | Non-email delivery rejected at [CommunicationDeliveryService.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Services/CommunicationDeliveryService.php:30) |
| Mews Events | Inquiry engine, quotes, event space, deposits, reminders, function sheets, event billing | Partial | Proposals/programs exist; no event-management product |
| Atomize RMS | Revenue recommendations, forecasting, optimized pricing | Absent | No RMS dependency or service |
| Mews POS/ePOS | Guest-centric restaurant POS, tableside ordering/payment, inventory upgrades | Partial retail only | Retail route only in [api.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/routes/api.php:91) |
| Mews Payments | Embedded processor payments | Absent | [PaymentService.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Services/PaymentService.php:23) records manual payments |
| Mews Terminals | Physical payment-terminal integration | Absent | No terminal client |
| Tokenization | Stored/tokenized card data | Absent | No card vault |
| Automated Reconciliation | Processor, bank, payout, and settlement matching | Absent | Internal manual reconciliation only |
| Multicurrency | Guest payment and settlement currencies | Partial | Internal FX model only |
| Accounts Receivable | Invoices, statements, reminders, debtor tracking | Partial | Financial summaries exist; no complete invoice/AR engine |
| Booking Engine | Public availability, pricing, reservations, vouchers, payment | Absent | Guest portal is not a public booking engine |
| Mews Connector app | Local printer/key-cutter/device bridge | Absent | No device command system |
| Connector API | PMS data/services integration API | Absent as compatible API | LodgeOps API is internal only |
| Booking Engine API | Custom booking widget/API | Absent | No public availability/booking route |
| Channel Manager API | OTA inventory and reservation distribution | Absent | No provider or channel API |
| POS API | Orders, tables, products, payments, POS webhooks | Absent | No POS contract |
| Virtual Concierge | Guest-facing digital concierge and messaging | Partial portal only | Portal has itinerary/pre-arrival/folio/survey |
| Flexkeeping | Dynamic housekeeping schedules, staff forecast, maintenance, AI voice, contractors | Partial internal | LodgeOps has housekeeping state/tasks, not Flexkeeping scope |
| Dynamic Pricing | Automated pricing changes | Absent | No pricing-rule engine |
| Demand Forecasting & Controls | Forecasting, restrictions, demand controls | Absent | No forecast model |
| Flexible Financing / YouLend | Financing for hospitality operators | Absent | No financing domain |
| Data & Reporting | Exportable operational/financial reporting product | Partial | Internal reports/exports only |
| Open API platform | APIs, webhooks, portfolio integration | Partial internal API only | [api.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/routes/api.php:45) |
| Security & Compliance product surface | Enterprise identity, certifications, trust/status tooling | Partial application security only | Security mechanisms exist, enterprise evidence absent |

Supporting first-party sources:

- [Mews Analytics add-on](https://help.mews.com/s/article/connect-to-mews-analytics-as-an-add-on)
- [Portfolio plan and Mews Events](https://help.mews.com/s/article/Understanding-the-Portfolio-pricing-plan?language=en_US)
- [Mews Digital Key](https://help.mews.com/s/article/How-to-set-up-the-Bluetooth-Digital-Key-in-Mews-Operations)
- [Mews Open API](https://www.mews.com/en/products/api)
- [Mews product catalog](https://www.mews.com/en/products)

## 4. Groups & Chains / Multi-Property

Sources: [Mews Groups & Chains](https://www.mews.com/en-gb/solutions/groups), [Mews Portfolio plan](https://help.mews.com/s/article/Understanding-the-Portfolio-pricing-plan?language=en_US), and [central rate groups](https://help.mews.com/s/article/Managing-rate-groups-in-Mews-Multi-Property).

| Groups & Chains capability | LodgeOps status | LodgeOps evidence |
|---|---|---|
| Group properties by brand | Absent | No brand/group model |
| Group properties by region | Absent | No region portfolio model |
| Group properties by management company | Absent | No chain-management model |
| Central portfolio overview | Partial | Tenant owns multiple properties, but no chain control plane |
| Bulk property administration | Absent | No bulk property command/API |
| Unified reservation data across properties | Partial | Tenant-scoped reservation data only |
| Unified guest data across properties | Partial | Tenant guest relation exists; no cross-property guest intelligence |
| Central rate groups | Absent | No rate-group model |
| Bulk rate changes | Absent | No rate engine |
| Central corporate rates | Absent | No corporate-rate model |
| Central promotional rates | Absent | No promotion/rate strategy engine |
| Central vouchers | Absent | No voucher model |
| Central products/policies | Absent | No portfolio propagation |
| Shared roles across properties | Partial | Role exists in [MembershipRole.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Enums/MembershipRole.php:5), but no central bulk role administration |
| Bulk user creation | Absent | Membership records exist; no bulk provisioning workflow |
| Bulk user removal | Absent | No chain-level provisioning workflow |
| Centralized permission templates | Absent | Role enum only |
| SSO across properties | Absent | Local Laravel session authentication only |
| SCIM provisioning | Absent | No SCIM provider |
| Passkeys across properties | Absent | No passkey implementation |
| Multi-property booking engine | Absent | No public booking route |
| Cross-property booking | Absent | Internal staff reservations are not a booking engine |
| Cross-property cross-selling | Absent | No guest-facing cross-property upsell flow |
| One API connection for entire portfolio | Absent | API requires internal tenant context; no portfolio token |
| Cross-property CRM synchronization | Absent | No external CRM connector |
| Cross-property housekeeping synchronization | Absent | No external housekeeping connector |
| Cross-property payment reporting | Absent | No processor/settlement system |
| Cross-property AR reporting | Absent | No complete invoice/AR ledger |
| Chain-level BI dashboards | Partial internally | Internal finance/operations views, not Mews Analytics |
| Pickup analytics across properties | Absent | No pickup/booking-pace engine |
| ADR/RevPAR portfolio reporting | Absent | No ADR or RevPAR implementation |
| Central RMS | Absent | No RMS |
| Property replication | Absent | No cloning/deployment workflow |
| New-property rollout automation | Absent | No onboarding automation |
| Centralized training/e-learning | Absent | No learning system |
| Central event/group management | Partial | Proposals and CRM opportunities only |
| Portfolio Marketplace subscriptions | Absent | No app directory/subscription system |
| Single production instance with automatic product releases | Unverified/absent | No comparable deployment control surface in repository |

## 5. Security and compliance

Mews publicly claims SSO, SCIM, passkeys, trusted devices, device approvals, 2FA, 99.9% SLA, 24/7 monitoring, disaster recovery, ISO 27001, SOC 2 Type 2, PCI DSS, GDPR, NF525, tokenization, P2PE, and PSD2/3D Secure. Source: [Mews Security & Compliance](https://www.mews.com/en-gb/security-at-mews).

| Security/compliance capability | LodgeOps status | LodgeOps evidence |
|---|---|---|
| Application authentication | Executable | Laravel session guard in [auth.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/config/auth.php:40) |
| Sanctum API authentication | Executable | Sanctum middleware in [api.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/routes/api.php:45) |
| Tenant isolation | Executable | [ResolveTenant.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Http/Middleware/ResolveTenant.php:18) |
| Property-scoped membership | Executable | [Membership.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Models/Membership.php:16) |
| Role-based permissions | Executable | [TenantPolicy.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Policies/TenantPolicy.php:9) |
| Finance/operations role separation | Executable | [MembershipRole.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Enums/MembershipRole.php:23) |
| MFA/TOTP | Executable | Filament MFA traits in [User.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Models/User.php:29) |
| MFA recovery | Executable | Filament recovery trait in [User.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Models/User.php:29) |
| Email verification | Executable | `MustVerifyEmail` in [User.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Models/User.php:29) |
| Password recovery | Executable | Password broker in [auth.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/config/auth.php:95) |
| Audit records | Executable | [Audit.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Models/Audit.php:8) |
| Immutable audit records | Executable | Update/delete protection in [Audit.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Models/Audit.php:20) |
| Idempotent command handling | Executable | `idempotent` middleware in [bootstrap/app.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/bootstrap/app.php:23) |
| Tenant context restoration for jobs | Executable | [PublishOutboxMessage.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Jobs/PublishOutboxMessage.php:39) |
| Encrypted staff sessions | Executable by Laravel/Filament | Documented in [security/page.tsx](/Users/ceron/Developer/Projects/lodge-ops/apps/web/src/app/security/page.tsx:10) |
| CSRF protection | Executable by Laravel/Filament | Documented in [security/page.tsx](/Users/ceron/Developer/Projects/lodge-ops/apps/web/src/app/security/page.tsx:10) |
| Hashed guest portal tokens | Executable | [GuestPortalAccessToken.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Models/GuestPortalAccessToken.php:8) |
| Security headers | Executable in public Next app | [next.config.ts](/Users/ceron/Developer/Projects/lodge-ops/apps/web/next.config.ts:11) |
| SSO | Absent | No SAML/OIDC/enterprise identity provider |
| SCIM provisioning | Absent | No SCIM endpoints or client |
| Passkeys | Absent | No WebAuthn/passkey implementation |
| Trusted devices | Absent | No trusted-device model |
| Device-level approvals | Absent | No device-approval workflow |
| SAML | Absent | No SAML dependency/configuration |
| OIDC | Absent | No OIDC provider/client |
| PostgreSQL row-level security | Absent | Application policies only; architecture describes RLS as future defense-in-depth |
| Tokenization | Absent | No card vault/token provider |
| Point-to-point encryption | Absent | No terminal/card-processing path |
| PSD2/3D Secure | Absent | No payment processor |
| PCI DSS evidence | Absent | No certification evidence in repository |
| SOC 2 evidence | Absent | No certification evidence in repository |
| ISO 27001 evidence | Absent | No certification evidence in repository |
| GDPR compliance evidence | Unverified | No formal DPA/assessment evidence in repository |
| NF525 evidence | Absent | No fiscal/regulatory certification |
| Uptime SLA | Absent | No LodgeOps SLA/status contract |
| 24/7 monitoring | Absent | No monitoring integration or status system |
| Disaster recovery | Not implemented/verified | Public page says restoration drills remain production responsibility |
| Public security trust center | Absent | Only a static marketing security page |
| Public service status | Absent | No status page integration |

## 6. Open API and Marketplace management

Sources: [Mews Open API](https://www.mews.com/en/products/api), [Mews Connector API](https://docs.mews.com/connector-api/operations), [Booking Engine API](https://docs.mews.com/booking-engine-guide/booking-engine-api/operations), [Channel Manager API](https://docs.mews.com/channel-manager-api/channel-manager-operations), [POS API](https://docs.mews.com/pos-api/operations), and [Marketplace administration](https://help.mews.com/s/article/connect-and-disconnect-integrations).

| Mews API/Marketplace capability | LodgeOps status | LodgeOps evidence |
|---|---|---|
| Public developer API | Partial internal API only | Internal `/api/v1` in [api.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/routes/api.php:45) |
| Open API documentation portal | Absent | No public LodgeOps developer portal |
| Connector API | Absent | No Mews-compatible Connector contract |
| Channel Manager API | Absent | No channel-manager contract |
| Booking Engine API | Absent | No public booking API |
| POS API | Absent | No POS API contract |
| Portfolio API | Absent | No cross-property partner token or single-call portfolio API |
| Real-time API webhooks | Absent | No inbound webhook controller |
| WebSocket event API | Absent | No WebSocket server/client/subscription registry |
| Export API/data-warehouse export | Absent | Internal reports are not an export API |
| Two-way CRM sync | Absent | Internal organizations/opportunities only |
| Guest-profile synchronization | Absent externally | No provider execution |
| Reservation synchronization | Absent externally | No provider execution |
| Spend synchronization | Absent | No external CRM/BI sync |
| Company-information synchronization | Absent | No provider connector |
| Housekeeping synchronization | Absent | No external housekeeping client |
| Guest journey synchronization | Absent | No kiosk/concierge/messaging integration |
| Key-cutter integration | Absent | No device commands |
| Printer integration | Absent | No device commands |
| Payment-terminal integration | Absent | No terminal client |
| Provider access-token lifecycle | Absent | No Mews ClientToken/AccessToken handling |
| Portfolio access-token handling | Absent | No portfolio token |
| Partner certification workflow | Absent | No Mews certification tests |
| Mews demo/sandbox client | Absent | No Mews fixture/client |
| Mews cursor pagination | Absent | Internal Laravel resources only |
| Mews request-limit handling | Absent | No provider retry/rate-budget client |
| Marketplace directory | Absent | No app/provider directory |
| Marketplace app search | Absent | Integration list is tenant-local records |
| Marketplace category filters | Absent | No provider taxonomy |
| Featured integrations | Absent | No equivalent |
| Popular integrations | Absent | No equivalent |
| Marketplace pagination | Absent | No Marketplace app pages |
| Marketplace app detail pages | Absent | No provider profiles |
| Standard integration classification | Absent | No standard/add-on model |
| Mews Add-On classification | Absent | No subscription product model |
| Marketplace connection limits | Absent | No package connection-limit logic |
| Admin connect flow | Adapter-only | Admin can save generic config |
| Partner setup handoff | Absent | No external setup workflow |
| Mews add-on trial | Absent | No trial/subscription billing |
| Mews add-on purchase | Absent | No Marketplace purchase path |
| Monthly add-on billing | Absent | No billing provider |
| “My subscriptions” management | Absent | No subscription model |
| Enable/disable integration state | Partial local state only | `status` is stored in [IntegrationConnectionService.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Services/IntegrationConnectionService.php:27), but does not control data transfer |
| Disconnect/delete provider config | Partial local configuration only | Resource deletion is disabled in [IntegrationConnectionResource.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Filament/Resources/IntegrationConnections/IntegrationConnectionResource.php:40) |
| Pause data transfer on disable | Absent | No provider data transfer exists |
| Provider health checks | Absent | `last_synced_at` is a field, not an executing health check |
| Provider troubleshooting | Absent | No provider logs/support handoff |
| Partner support contact | Absent | No Marketplace provider profile |
| Marketplace legal/data-transfer controls | Absent | No third-party integration terms/data-processing workflow |
| External email delivery | Executable | Laravel mail transport in [CommunicationDeliveryService.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Services/CommunicationDeliveryService.php:80) |
| Private iCalendar feed | Executable, one-way | [CalendarFeedService.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Services/CalendarFeedService.php:15) |
| Internal outbox | Executable, internal only | [OutboxBatchPublisher.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Services/Automation/OutboxBatchPublisher.php:21) |
| External webhook delivery | Absent | Outbox dispatches internal automation jobs only |
| External payment execution | Absent | Manual ledger behavior in [PaymentService.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Services/PaymentService.php:23) |

The exact LodgeOps integration boundary is six configuration types:

```text
email
calendar
accounting
payment
signature
webhook
```

Those types are declared in [IntegrationConnection.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Models/IntegrationConnection.php:7), while the service only validates and saves configuration in [IntegrationConnectionService.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Services/IntegrationConnectionService.php:27). The Filament UI itself describes the records as adapters requiring external credentials: [IntegrationConnectionResource.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Filament/Resources/IntegrationConnections/IntegrationConnectionResource.php:98).

## 7. Live public website stack

Mews live-site observations were taken from the current Marketplace page, response headers, HTML/RSC payload, sitemap index, and linked site surface. Sources: [Mews Marketplace](https://www.mews.com/en/products/marketplace), [Mews sitemap index](https://www.mews.com/sitemap.xml), and [Mews Open API](https://www.mews.com/en/products/api).

| Website capability | Mews live observation | LodgeOps status | LodgeOps evidence |
|---|---|---|---|
| Frontend framework | Next.js/App Router/RSC markers | Partial | Next.js dependency in [package.json](/Users/ceron/Developer/Projects/lodge-ops/apps/web/package.json:13) |
| React runtime | React frontend | Executable | React dependency in [package.json](/Users/ceron/Developer/Projects/lodge-ops/apps/web/package.json:13) |
| Server-rendered content | Marketplace search/category/app content in initial HTML | Partial | Next App Router pages exist, but no comparable dynamic catalog |
| RSC payload | `__next_f`, RSC-related headers | Partial | Next app exists; no equivalent CMS/RSC data surface |
| Edge hosting | `server: Vercel`, `x-vercel-id` | Unverified | Repository has no production hosting proof |
| Edge cache | `x-vercel-cache`, Vercel cache headers | Unverified | No CDN configuration in repository |
| Dynamic localized Marketplace route | `/[locale]/products/marketplace/[[...slug]]` | Absent | Only four public page routes |
| Seven locale sites | `de`, `en`, `en-gb`, `es`, `fr`, `it`, `nl` | Absent | Root layout is `lang="en"` in [layout.tsx](/Users/ceron/Developer/Projects/lodge-ops/apps/web/src/app/layout.tsx:25) |
| Localized sitemap index | Seven locale sitemap files | Absent | No `sitemap.ts` or sitemap files in [apps/web/src/app](/Users/ceron/Developer/Projects/lodge-ops/apps/web/src/app) |
| English sitemap size | 2,734 URLs in current snapshot | Absent | LodgeOps has no public content sitemap |
| German sitemap | 761 URLs in current snapshot | Absent | No German locale |
| English-GB sitemap | 32 URLs in current snapshot | Absent | No English-GB locale |
| Spanish sitemap | 937 URLs in current snapshot | Absent | No Spanish locale |
| French sitemap | 907 URLs in current snapshot | Absent | No French locale |
| Italian sitemap | 511 URLs in current snapshot | Absent | No Italian locale |
| Dutch sitemap | 725 URLs in current snapshot | Absent | No Dutch locale |
| CMS/content collection | RSC payload exposes page collection/media records; vendor not proven | Absent | Static JSX pages only |
| Custom media API | `/api/media/file/...` | Absent | No marketing media API |
| Blob/object storage | Public Vercel Blob URL observed | Unverified | No production object-storage config |
| Marketplace search | Server-rendered “Search integrations” | Absent | No public search surface |
| Marketplace category filter | 25 category selector options | Absent | No Marketplace taxonomy |
| Marketplace pagination | Current HTML links through page 60 | Absent | No catalog pagination |
| Featured integrations | Tripadvisor, profitize, BEONX | Absent | No featured provider directory |
| Popular integrations | Expedia, Booking.com, SiteMinder | Absent | No partner directory |
| Product catalog | Large platform catalog with product pages | Partial | LodgeOps markets core workflows in [page.tsx](/Users/ceron/Developer/Projects/lodge-ops/apps/web/src/app/page.tsx:5) |
| Blog | Mews blog linked in global navigation | Absent | No blog route |
| Research | Mews research/resource pages | Absent | No research route |
| Webinars | Mews webinar resources | Absent | No webinar route |
| Events | Mews events/resources pages | Absent | No events route |
| Developer resources | Developer docs and API links | Absent | No public developer portal |
| Release notes | PMS and POS release-note links | Absent | No release-note system |
| Trust center | Trust Center linked from site | Absent | Static security page only |
| Service status | Public status link | Absent | No status page |
| Careers/company/legal content | Extensive global navigation | Absent | No comparable public content architecture |
| GTM | Google Tag Manager script observed | Absent | No GTM in LodgeOps web source |
| Google Analytics/gtag | `gtag` markers observed | Absent | No analytics dependency/config |
| Consent management | OneTrust script/config observed | Absent | No consent-management provider |
| Experimentation | AB Tasty script observed | Absent | No experimentation provider |
| Tracking configuration | Trackingplan config observed | Absent | No tracking-plan integration |
| Fraud/traffic analytics | Anura marker observed | Absent | No equivalent |
| HubSpot strings | Strings observed, CMS vendor not proven | Unverified | No HubSpot dependency or integration |
| Motion system | Motion-named chunks and `LazyMotion` markers | Absent | No Motion dependency in [package.json](/Users/ceron/Developer/Projects/lodge-ops/apps/web/package.json:13) |
| Swiper | Swiper-named chunks | Absent | No Swiper dependency |
| Rive assets | Rive-format assets observed in earlier live-page probe; not claimed universally | Absent/unverified | No Rive asset/dependency |
| Optimized image formats | SVG, WebP, AVIF, JPG, PNG assets | Partial | CSS/static assets only |
| Font preloading/self-hosted font pipeline | Multiple preloaded WOFF2 assets | Partial | LodgeOps imports Google Fonts in [globals.css](/Users/ceron/Developer/Projects/lodge-ops/apps/web/src/app/globals.css:1) |
| Public contact/demo funnel | Contact/demo navigation present; exact form execution not inferred from HTML alone | Partial marketing links | LodgeOps only links to staff application |
| Public auth boundary | Mews login link and operational platform separation | Partial | LodgeOps links to Laravel `/manage` from [layout.tsx](/Users/ceron/Developer/Projects/lodge-ops/apps/web/src/app/layout.tsx:39) |
| Security headers | Mews headers observed through hosting; exact policy not fully inferred | Executable locally | LodgeOps defines headers in [next.config.ts](/Users/ceron/Developer/Projects/lodge-ops/apps/web/next.config.ts:11) |
| Public product navigation | Mews platform, solutions, resources, company, legal, careers | Partial | LodgeOps has home/features/pricing/security in [layout.tsx](/Users/ceron/Developer/Projects/lodge-ops/apps/web/src/app/layout.tsx:33) |

## Corrected Worker D conclusion

LodgeOps has:

- A real tenant/property model.
- Property-scoped memberships and roles.
- Application-level MFA and recovery.
- Immutable audit records.
- An internal versioned API.
- Internal reservations, guests, operations, finance, and retail workflows.
- Executable Laravel email delivery.
- A one-way private iCalendar feed.
- An internal queued outbox.
- A generic, tenant-scoped integration configuration screen.

LodgeOps does not currently have:

- Any executable Mews Marketplace connector.
- Any OTA/channel-management adapter.
- Any external accounting adapter.
- Any payment processor or terminal.
- Any POS/ePOS integration.
- Any RMS or revenue adapter.
- Any lock, key, digital-key, or ID-verification adapter.
- Any SMS, WhatsApp, guest-messaging, or concierge adapter.
- Any loyalty, reputation, spa, regulatory, HR, or building-control adapter.
- Any Mews-compatible Connector, Booking Engine, Channel Manager, or POS API.
- Any inbound/outbound webhook integration.
- Any Marketplace directory, search, categories, subscriptions, provider setup, or connection lifecycle.
- Any Groups & Chains central rate, voucher, portfolio API, replication, or chain analytics system.
- SSO, SCIM, passkeys, trusted devices, device approvals, or enterprise identity federation.
- Public certification evidence for SOC 2, ISO 27001, PCI DSS, GDPR, NF525, or PSD2.
- A Mews-scale localized CMS, content catalog, resource center, analytics/consent stack, sitemap system, or partner marketplace.

No application files were edited during the original pass. The shared checkout was already dirty from other work, and this audit preserved those changes; the documentation was later extended with the GitHub/OpenAPI cross-check below.

## Current Mews OS and native distribution additions

These capabilities were verified on the current Mews product catalog after the original worker output was written.

Sources: [Mews product catalog](https://www.mews.com/en/products), [Introducing Mews OS](https://www.mews.com/en/introducing-mews-os), and [Mews/SiteMinder Channel Manager announcement](https://www.mews.com/en/press/mews-siteminder-channel-manager-partnership).

| Current Mews capability | Availability/status note | LodgeOps status | LodgeOps evidence | Mismatch |
|---|---|---|---|---|
| Mews OS unified operating-system layer | Current platform positioning; umbrella rather than a separate deployable module | PARTIAL | [api.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/routes/api.php:45) | LodgeOps has internal modules but no unified PMS/POS/RMS/payments/distribution operating system. |
| Native Mews Channel Manager powered by SiteMinder | Announced May 2026; rollout/availability may be property- or market-gated | ABSENT | [IntegrationConnection.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Models/IntegrationConnection.php:5) | No channel-manager execution, OTA mapping, or SiteMinder adapter exists. |
| Native distribution through 400+ OTAs | Public Mews/SiteMinder product claim | ABSENT | [api.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/routes/api.php:45) | No OTA/channel publication or reservation ingestion exists. |
| One contract, one bill, and one support line for native distribution | Public Mews/SiteMinder product claim | ABSENT | [IntegrationConnectionService.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/app/Services/IntegrationConnectionService.php:8) | LodgeOps has no provider contracting, billing, or support integration layer. |
| ePOS as a separately marketed product surface | Current Mews catalog lists ePOS separately from POS | ABSENT | [api.php](/Users/ceron/Developer/Projects/lodge-ops/apps/api/routes/api.php:88) | LodgeOps has retail sales, not restaurant ePOS, tableside ordering, or embedded F&B payments. |

## Public GitHub stack-source cross-check

The attached GitHub research was checked, but public code is kept separate from product parity claims. The full link inventory and exact API deltas are in the [GitHub/OpenAPI re-audit](mews-vs-lodgeops-github-openapi-audit.md).

| Public source | Verified signal | Comparison consequence |
|---|---|---|
| [MewsSystems/open-api-docs](https://github.com/MewsSystems/open-api-docs) | First-party docs monorepo covering Connector, Booking Engine, Channel Manager, POS, Open API, Payments Checkout, and Loyalty Partner | Primary source for API feature comparison; not core PMS application source. |
| [Mews Platform Documentation](https://www.mews.com/en/legal/platform-documentation) | Azure serverless multi-tenant SaaS; Azure App Service, Azure SQL Database, Azure Storage, Cosmos DB for logs; C#/.NET backend; JavaScript/TypeScript and React frontend; Flutter and Kotlin mobile; LaunchDarkly staged releases | Current platform-level technology disclosure; still not source code or a per-feature service map. |
| [Live Connector Swagger](https://api.mews.com/Swagger/connector/swagger.yaml) | OpenAPI 3.0.4, 205 current paths/operations | Supersedes stale third-party split maps for current Connector coverage. |
| [api-evangelist/mews](https://github.com/api-evangelist/mews) | 74 split files and 198 examples | Useful grepable cross-check, but behind the live contract for newer cancellation-policy, resource-category, preauthorization, and Guest Portal operations. |
| [Velocity-BPA/n8n-nodes-mews](https://github.com/Velocity-BPA/n8n-nodes-mews) | Unofficial Connector client with auth, POST transport, cursor pagination, and ten resource modules | Demonstrates integration implementation patterns; incomplete coverage and BSL-licensed. |
| [code-rabi/mews-mcp](https://github.com/code-rabi/mews-mcp) | Unofficial MCP server with 54 tool declarations and an explicit missing-operation backlog | Useful omission signal; not a complete Mews API implementation or authority. |
| [MewsSystems/fiscalizations](https://github.com/MewsSystems/fiscalizations) | Public C#/.NET 8 fiscalization library with async I/O, immutable DTOs, country packages, and CI/CD | Stack signal only; does not prove the private PMS backend architecture. |
| [MewsSystems/mews-flutter](https://github.com/MewsSystems/mews-flutter) | Public Flutter/Dart packages including Optimus and kiosk mode | UI/mobile signal only; does not prove the web or PMS stack. |
| [MewsSystems/reservations-interview](https://github.com/MewsSystems/reservations-interview) | .NET 8, SQLite, React/TypeScript, TanStack sample app | Explicitly a sample/interview repository, not production architecture. |
| [Cloudbeds OpenAPI](https://github.com/cloudbeds/openapi-specs) and [Apaleo API map](https://github.com/api-evangelist/apaleo) | Separate PMS API benchmark sources | Checked for comparison context; not counted as Mews functionality. |

The checked public Mews organization did not expose the core PMS backend source, but the official Platform Documentation does disclose the high-level production stack. The service-by-service database, queue, event-bus, AI, and deployment topology remains undisclosed.
