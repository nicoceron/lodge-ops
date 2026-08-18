<!-- Generated from the completed row-level audit worker output. Statuses and claims are preserved verbatim from the worker; verify live Mews URLs before using this as a product contract. -->

Understood. This is the actual Worker A audit: one row per distinct advertised capability in the requested Mews scope. No application files were edited during the original pass; this document now includes the later GitHub/OpenAPI addendum below. Technology by feature is tracked in the [up-to-date technology audit](mews-vs-inn-technology-audit.md).

Status means:

- **FULL:** materially implemented in Inn.
- **PARTIAL:** a narrower/internal analogue exists.
- **ABSENT:** no implementation.
- **ADAPTER-ONLY:** configuration boundary exists, but no executable integration.

## Mews sources

| Key | Official source |
|---|---|
| S1 | [Mews PMS](https://www.mews.com/en/property-management-system) |
| S2 | [Reservation Management](https://www.mews.com/en-gb/products/reservation-management) |
| S3 | [Rate Management](https://www.mews.com/en/products/hotel-rate-management) |
| S4 | [Space Management](https://www.mews.com/en/products/hotel-space-management) |
| S5 | [Front Desk Software](https://www.mews.com/en/products/hotel-front-desk-software) |
| S6 | [Booking Engine](https://www.mews.com/en/products/booking-engine) |
| S7 | [Guest Intelligence](https://www.mews.com/en/products/hotel-guest-management-software) |
| S8 | [Guest Check-In](https://www.mews.com/en/products/guest-self-check-in) |
| S9 | [Guest Portal help page](https://help.mews.com/s/article/what-is-online-guest-services) |
| S10 | [Flexkeeping / Housekeeping](https://www.mews.com/en/products/housekeeping-software) |
| S11 | [Upsells](https://www.mews.com/en/products/upsells) |
| S12 | [Digital Key](https://www.mews.com/en-gb/products/digital-key) |
| S13 | [Mews Kiosk](https://www.mews.com/en/products/check-in-kiosk) |
| S14 | [Virtual Concierge](https://www.mews.com/en-gb/products/virtual-concierge) |
| S15 | [Groups & Chains](https://www.mews.com/en/solutions/groups) |
| S16 | [Sales & MICE / Events](https://www.mews.com/en/solutions/sales-mice-managers) |
| S17 | [Kiosk help documentation](https://help.mews.com/s/article/what-are-mews-kiosks) |
| S18 | [Kiosk setup documentation](https://help.mews.com/s/article/Setting-up-your-Mews-Kiosk) |
| S19 | [Upsell configuration help](https://help.mews.com/s/article/How-to-create-upsells-to-increase-revenue-in-Mews-Operations) |
| S20 | [Digital Key setup help](https://help.mews.com/s/article/How-to-set-up-the-Bluetooth-Digital-Key-in-Mews-Operations) |
| S21 | [Viqal third-party virtual concierge](https://www.mews.com/en/products/marketplace/viqal-virtual-concierge) |

## Inn evidence keys

- **E1:** [API routes](../apps/api/routes/api.php:45), including properties, guests, resources, reservations, allocations, service occurrences, blocks, tasks, folios, proposals, payments, deposits, catalog, retail, CRM, and integrations.
- **E2:** [Guest portal API routes](../apps/api/routes/api.php:32) and [guest portal web routes](../apps/api/routes/web.php:8).
- **E3:** [Reservation model](../apps/api/app/Models/Reservation.php:38), [reservation status](../apps/api/app/Enums/ReservationStatus.php:5).
- **E4:** [ReservationService](../apps/api/app/Services/ReservationService.php:27).
- **E5:** [AvailabilityService](../apps/api/app/Services/AvailabilityService.php:17) and [AllocationWorkflowService](../apps/api/app/Services/AllocationWorkflowService.php:20).
- **E6:** [MasterCalendar](../apps/api/app/Filament/Pages/MasterCalendar.php:23) and [OperationsBoard](../apps/api/app/Filament/Pages/OperationsBoard.php:26).
- **E7:** [Resource](../apps/api/app/Models/Resource.php:29), [ResourceCategory](../apps/api/app/Models/ResourceCategory.php:19), [ResourceBlock](../apps/api/app/Models/ResourceBlock.php:14), and [ResourceKind](../apps/api/app/Enums/ResourceKind.php:5).
- **E8:** [Program](../apps/api/app/Models/Program.php:18), [Program form](../apps/api/app/Filament/Resources/Programs/Schemas/ProgramForm.php:72), and [ServiceOccurrence](../apps/api/app/Models/ServiceOccurrence.php:18).
- **E9:** [ProposalService](../apps/api/app/Services/ProposalService.php:18), [Proposal](../apps/api/app/Models/Proposal.php:9), and proposal routes in [api.php](../apps/api/routes/api.php:76).
- **E10:** [Guest](../apps/api/app/Models/Guest.php:9), [Guest form](../apps/api/app/Filament/Resources/Guests/Schemas/GuestForm.php:12), and [GuestMergeService](../apps/api/app/Services/GuestMergeService.php:13).
- **E11:** [GuestPortalService](../apps/api/app/Services/GuestPortalService.php:19), [GuestPortalTokenService](../apps/api/app/Services/GuestPortalTokenService.php:16), and portal controllers.
- **E12:** [HousekeepingService](../apps/api/app/Services/HousekeepingService.php:10), [HousekeepingStatus](../apps/api/app/Enums/HousekeepingStatus.php:5), [OperationalTask](../apps/api/app/Models/OperationalTask.php:14), and [OperationsBoard](../apps/api/app/Filament/Pages/OperationsBoard.php:58).
- **E13:** [MessageTemplateService](../apps/api/app/Services/MessageTemplateService.php:18), [CommunicationDeliveryService](../apps/api/app/Services/CommunicationDeliveryService.php:18), and [AutomationActionExecutor](../apps/api/app/Services/Automation/AutomationActionExecutor.php:32).
- **E14:** [PaymentService](../apps/api/app/Services/PaymentService.php:15) and [FolioService](../apps/api/app/Services/FolioService.php:14).
- **E15:** [ExtendedOperationsController](../apps/api/app/Http/Controllers/Api/V1/ExtendedOperationsController.php:31), catalog, stock, retail, organizations, opportunities, and integrations.
- **E16:** [IntegrationConnection](../apps/api/app/Models/IntegrationConnection.php:5) and [IntegrationConnectionService](../apps/api/app/Services/IntegrationConnectionService.php:8).

## 1. PMS, reservation management, front desk, rates, and space management

| # | Mews advertised feature | Source | Status | Inn evidence | Mismatch |
|---:|---|---|---|---|---|
| 1 | Unified PMS workspace for bookings, payments, housekeeping, and guest communications | S1 | PARTIAL | E1, E3, E12, E14 | Inn has these domains but not one integrated payment, messaging, and housekeeping automation layer. |
| 2 | Centralized reservations calendar | S1 | FULL | E6 | Inn has a tenant/property-scoped Master Calendar. |
| 3 | Unified reservation timeline | S1 | PARTIAL | E6 | Inn renders calendar events but lacks Mews’ unified interactive front-desk timeline behavior. |
| 4 | Smart reservation detail window with key guest information | S1 | PARTIAL | E3, [ReservationInfolist](../apps/api/app/Filament/Resources/Reservations/Schemas/ReservationInfolist.php:12) | Inn shows core guest and stay data but not the full Mews guest-intelligence view. |
| 5 | Automated guest communications | S1 | PARTIAL | E13 | Inn automates email and internal notifications but not SMS or guest messaging. |
| 6 | Individual reservation creation | S1/S2 | FULL | E1, E3, E4 | Inn supports staff-created reservations. |
| 7 | Reservation modification | S2/S5 | FULL | E1, E3 | Inn exposes reservation update routes and forms. |
| 8 | Reservation groups | S5 | ABSENT | E1; no reservation-group model or route | Inn has no first-class group reservation object. |
| 9 | Bulk check-ins | S5 | ABSENT | E1; only per-reservation transition exists | Inn has no bulk check-in command. |
| 10 | Searchable reservation timeline | S5 | PARTIAL | E6 | Inn has date/property/lens filters but no full text-searchable Mews timeline. |
| 11 | Filterable reservation timeline | S5 | PARTIAL | E6 | Inn supports lenses and resource views but not the complete Mews filter set. |
| 12 | One-touch timeline optimization | S5 | ABSENT | E6 | No equivalent optimization action exists. |
| 13 | Adjustable timeline views | S5 | PARTIAL | E6 | Inn supports 7/14/30-day windows and lenses but not Mews’ adjustable operational views. |
| 14 | Arrivals and departures at a glance | S1/S5 | FULL | E6, OperationsBoard arrivals/departures | Inn explicitly projects arrivals and departures. |
| 15 | Room/resource assignment | S1/S5 | FULL | E1, E5, E7 | Inn has allocations, resources, categories, and conflict checks. |
| 16 | Visual overbooking alerts | S2/S5 | PARTIAL | E5 | Inn blocks conflicts in the backend but lacks Mews’ visual alert workflow. |
| 17 | Gap detection | S2 | PARTIAL | E5, E6 | Inn detects allocation conflicts but does not provide Mews gap-management UX. |
| 18 | Drag-and-drop stay movement | S2 | PARTIAL | E1, E3, E6 | Reservation dates can be edited, but no drag-and-drop interaction exists. |
| 19 | Stay extension | S2 | PARTIAL | E1, E3 | The API can update dates, but there is no Mews-style timeline extension flow. |
| 20 | Stay splitting | S2 | ABSENT | E1, E3 | No split-stay operation or model exists. |
| 21 | Live availability | S2 | PARTIAL | E5, E6 | Inn has internal conflict/availability checks but no complete live availability product. |
| 22 | Live availability across properties | S2 | PARTIAL | E1, E6, Property model | Property scoping exists, but no chain-wide shared availability view exists. |
| 23 | Two-way booking-engine synchronization | S2/S6 | ABSENT | E1; no booking-engine endpoint | Inn has no public booking engine to synchronize. |
| 24 | Two-way OTA synchronization | S2/S5 | ABSENT | E1, E16 | No OTA provider client or channel webhook exists. |
| 25 | Rate updates across every channel | S2/S3 | ABSENT | E1; no rate/channel routes | Inn cannot publish rates to channels. |
| 26 | Group allocations | S2 | ABSENT | E1; no allotment model or route | Inn has no group inventory allocation workflow. |
| 27 | Group block templates | S2 | ABSENT | E1, E7 | Resource blocks are property/resource blocks, not reusable group allotment templates. |
| 28 | Live group pickup tracking | S2 | ABSENT | No pickup model/service in E1/E3 | Inn cannot track group pickup. |
| 29 | Automatic release of unused group rooms | S2 | ABSENT | E1, E3 | No group release date or auto-release job exists. |
| 30 | Partner reminders for group blocks | S2 | ABSENT | E13 only has generic/deposit communications | There is no group-partner reminder workflow. |
| 31 | Synchronization with Mews Events | S2 | ABSENT | E1, E16 | Inn has no Mews Events or event-system connector. |
| 32 | Reservation holds | S1 | FULL | E3, E4 | Inn has a first-class hold status. |
| 33 | Hold expiry | S1 | FULL | E4 | Inn stores and expires reservation holds. |
| 34 | Check-in workflow | S1/S5 | FULL | E3, E4 | Inn has guarded checked-in transitions. |
| 35 | Check-out workflow | S1/S5 | FULL | E3, E4, E14 | Inn has guarded checkout and folio-closing behavior. |
| 36 | No-show workflow | S1 | FULL | E3, E4 | Inn has a no-show status and transition history. |
| 37 | Cancellation reason/history | S1 | FULL | E3, E4 | Inn stores lifecycle history and closure reasons. |
| 38 | Service/package reservations | S1/S2 | PARTIAL | E8 | Inn has programs and service occurrences but not Mews’ complete bookable-service distribution flow. |
| 39 | Service capacity management | S2 | FULL | E5, E8 | Inn enforces occurrence/resource capacity internally. |
| 40 | Reservation confirmation tasks | S1 | FULL | E8, E12, E13 | Inn can create task templates that materialize on confirmation. |
| 41 | Front-desk automation of repetitive tasks | S5 | PARTIAL | E12, E13 | Inn automates selected tasks and communications but not the complete Mews front-desk automation set. |
| 42 | Cloud-based PMS access | S5 | PARTIAL | E1, Filament application | Inn is web-based in the repository, but cloud hosting and Mews-grade availability are not verified. |
| 43 | Mobile/tablet front-desk operation | S5 | PARTIAL | E6 | Inn has browser UI but no dedicated mobile/tablet workflow was found. |
| 44 | Front desk can operate away from reception | S5 | PARTIAL | E6 | Inn pages can be accessed through the web app, but the specific mobile/off-desk design is not implemented or verified. |
| 45 | Reservation pricing | S2/S6 | PARTIAL | E3, ReservationForm pricing fields, E9 | Inn stores quoted totals and program prices but has no full rate engine. |
| 46 | Automated rate management | S3 | ABSENT | E3, E8 | No rate-management service or rate-plan model exists. |
| 47 | Manage room rates | S3 | ABSENT | E3, E8 | Inn has reservation/program prices, not room-rate catalogs. |
| 48 | Manage product rates | S3 | PARTIAL | E15 | Catalog items have prices, but no Mews rate-rule layer exists. |
| 49 | Manage space rates | S3/S4 | ABSENT | E7, E8 | Resources have no rate schedules. |
| 50 | Bulk rate changes across properties | S3 | ABSENT | E1, E3 | No bulk rate mutation exists. |
| 51 | Assign rates to categories | S3 | ABSENT | E7 | Resource categories have no attached rate-plan model. |
| 52 | Product bundles at special rates | S3 | PARTIAL | E8, E15 | Programs and catalog items can be combined internally, but no guest-facing rate bundle engine exists. |
| 53 | Pricing rules and restrictions | S3 | ABSENT | E1, E3 | No restriction/rule engine exists. |
| 54 | Age-based pricing | S3 | ABSENT | E3, E8 | No age-category or age-pricing logic exists. |
| 55 | Dynamic product pricing | S3 | ABSENT | E15 | Catalog prices are static. |
| 56 | Space-type pricing | S3 | ABSENT | E7 | Resource category does not drive pricing. |
| 57 | Prices by day | S3 | ABSENT | E3, E8 | No date-based price calendar exists. |
| 58 | Occupancy-based pricing | S2/S3 | ABSENT | E3, E8 | No occupancy pricing engine exists. |
| 59 | Rate inheritance | S2/S3 | ABSENT | E3 | No inherited rate hierarchy exists. |
| 60 | Length-of-stay pricing | S1/S2/S3 | ABSENT | E3 | No minimum/maximum/stay-length pricing engine exists. |
| 61 | Real-time rate propagation | S2/S3 | ABSENT | E1, E3 | Inn has no channel or booking-engine rate propagation. |
| 62 | Multi-currency rate presentation | S3/S6 | PARTIAL | E3, [ExchangeRateService](../apps/api/app/Services/ExchangeRateService.php:1) | Inn stores currencies and FX but does not offer guest-facing converted rates. |
| 63 | Booking Engine direct reservations | S6 | ABSENT | E1; no public availability or booking route | Inn has no public booking engine. |
| 64 | Booking Engine real-time availability | S6 | ABSENT | E1, E5 | Internal availability checks are not a public booking availability API. |
| 65 | Booking calendar with rules/restrictions | S6 | ABSENT | E1, E3 | No guest booking calendar or restriction display exists. |
| 66 | Native-language booking | S6 | ABSENT | E10 has guest language only | Inn has guest language fields but no localized booking engine. |
| 67 | Native-currency booking | S6 | ABSENT | E3 | Currency fields do not provide guest-facing booking conversion. |
| 68 | Booking-engine widget embedding | S6 | ABSENT | No public booking frontend or widget route | Inn cannot embed a booking engine. |
| 69 | Booking-engine photos and descriptions | S6 | ABSENT | E1; no public booking media model | No guest-facing room/space content exists. |
| 70 | Booking-engine custom guest fields | S6 | PARTIAL | E10, E11 | Inn collects profile/preferences data, but not configurable booking-engine fields. |
| 71 | Hourly booking | S4/S6 | PARTIAL | E3, E5, E7 | Arbitrary timestamps and resources exist, but no public hourly booking flow exists. |
| 72 | Daily booking | S4/S6 | PARTIAL | E3, E5, E7 | Generic intervals are supported, but not a guest-facing daily booking product. |
| 73 | Nightly booking | S4/S6 | PARTIAL | E3, E5 | Overnight reservations work internally, but no direct booking engine exists. |
| 74 | Monthly/long-stay booking | S4/S6 | PARTIAL | E3 | Date intervals allow long stays, but no monthly booking experience or pricing exists. |
| 75 | Manage every space/bookable service in one PMS | S4 | PARTIAL | E7, E8 | Inn has generic resources and programs, but no guest-facing space marketplace. |
| 76 | Adjustable space rates/rules/availability | S4 | PARTIAL | E5, E7 | Availability exists, but rates and rules do not. |
| 77 | Prevent space overbooking | S4 | FULL | E5 | Inn has transactional conflict and capacity checks. |
| 78 | Parking-space booking | S4/S6 | PARTIAL | E7, E15 | Parking could be modeled as a resource/catalog item, but there is no named parking booking flow. |
| 79 | Meeting-room booking | S4/S6 | PARTIAL | E7, E9 | Meeting spaces can be represented as resources/proposals, but not publicly booked. |
| 80 | Bike-rental booking | S4/S11 | PARTIAL | E7, E8, E15 | Assets/programs/catalog items exist, but no guest booking/upsell flow exists. |
| 81 | Party-room booking | S4 | PARTIAL | E7 | Generic resources can represent it, but no dedicated product workflow exists. |
| 82 | Co-working-space booking | S4 | PARTIAL | E7 | Generic resources can represent it, but no memberships or booking engine exist. |
| 83 | Karaoke-room booking | S4 | PARTIAL | E7 | Generic resources can represent it, but no dedicated booking flow exists. |
| 84 | Meeting-room photos in booking flow | S4 | ABSENT | No public booking media route/model | Inn has no guest-facing space photo catalog. |
| 85 | Co-working memberships | S4 | ABSENT | No membership product/service model | Inn has no recurring space membership workflow. |
| 86 | Sell the same space multiple times per day | S4 | PARTIAL | E3, E5 | Time intervals and conflict checks exist, but no optimized multi-sale space product exists. |

## 2. Guest Intelligence, guest check-in/out, and Guest Portal

| # | Mews advertised feature | Source | Status | Inn evidence | Mismatch |
|---:|---|---|---|---|---|
| 87 | 360-degree guest profile | S7 | PARTIAL | E10, E3 | Inn has profiles and stays but not a complete 360-degree commercial profile. |
| 88 | Combine stay history, preferences, and spend | S7 | PARTIAL | E10, E3, E14 | History and preferences exist, but cross-department spend intelligence does not. |
| 89 | Automatic duplicate detection and Match & Merge | S7 | FULL | E10 | Inn has executable guest merging and alias preservation. |
| 90 | AI Smart Tips | S7 | ABSENT | E10 | No AI guest-summary or recommendation service exists. |
| 91 | Guest notes | S7 | PARTIAL | E1 reservation notes, E3 | Inn has reservation notes, not a dedicated persistent guest-notes feature. |
| 92 | Guest tags | S7 | ABSENT | E10 | No guest-tag model or UI exists. |
| 93 | Guest relationships for couples/families/corporate groups | S7 | ABSENT | E3, E10 | Companion reservations are not relationship graphs. |
| 94 | Loyalty-program connections | S7 | ABSENT | E10 | No loyalty integration or membership model exists. |
| 95 | Loyalty enrollment | S7 | ABSENT | E10 | Inn cannot enroll or manage loyalty members. |
| 96 | Customizable check-in forms | S7/S8 | PARTIAL | E10, E11 | Inn has fixed guest/pre-arrival fields, not configurable compliance forms. |
| 97 | Customer lifetime insights | S7 | ABSENT | E10, E14 | No lifetime-value or cross-department spend aggregation exists. |
| 98 | Branded drag-and-drop email editor | S7 | PARTIAL | E13, [MessageTemplateResource](../apps/api/app/Filament/Resources/MessageTemplates/MessageTemplateResource.php:1) | Inn has versioned templates but no drag-and-drop editor. |
| 99 | Automated check-in emails | S7 | PARTIAL | E13 | Automated email exists, but not the full Mews Guest Intelligence communication system. |
| 100 | Automated check-in reminders | S7 | PARTIAL | E13 | Generic automation exists, but no complete Mews reminder journey. |
| 101 | SMS check-in reminders | S7 | ABSENT | [CommunicationDeliveryService.php](../apps/api/app/Services/CommunicationDeliveryService.php:18) | Delivery explicitly supports email rather than SMS. |
| 102 | Guest Portal messaging to reception | S7/S9/S14 | ABSENT | E2, E11 | Inn’ portal has no message-thread or reception-chat route. |
| 103 | Online check-in | S8/S9 | PARTIAL | E2, E11 | Inn supports pre-arrival data collection but does not transition the reservation through online check-in. |
| 104 | Online check-out | S8/S9 | ABSENT | E2, E3 | No guest checkout route exists. |
| 105 | Mobile-first guest journey | S8/S14 | PARTIAL | E2 | Inn has a web portal but no complete mobile check-in/out journey. |
| 106 | Secure web Guest Portal | S8/S9 | PARTIAL | E2, E11 | Inn has a secure tokenized portal, but it lacks Mews’ complete check-in/out/messaging scope. |
| 107 | Portal access through web, mobile, or tablet | S9 | PARTIAL | E2 | Web access exists; native/mobile-specific behavior is not implemented. |
| 108 | No-app guest access through a link | S9/S14 | FULL | E2, [GuestPortalTokenService.php](../apps/api/app/Services/GuestPortalTokenService.php:16) | Inn provides tokenized link access without requiring an app. |
| 109 | Pre-fill reservation details | S9 | PARTIAL | E11 | Inn displays reservation data, but does not provide Mews’ full editable online-registration flow. |
| 110 | Identity-document scanning | S9 | ABSENT | E10, E11 | Guest document fields exist, but no scanner or document-capture workflow exists. |
| 111 | Verification selfie/photo | S9 | ABSENT | E10, E11 | No identity-photo verification exists. |
| 112 | Add companion details before arrival | S9 | PARTIAL | E3, [ReservationGuest.php](../apps/api/app/Models/ReservationGuest.php:10) | Staff can manage companions, but the guest portal cannot add them. |
| 113 | Display property house rules | S9 | PARTIAL | E11, [GuestPortalDocument.php](../apps/api/app/Models/GuestPortalDocument.php:8) | Generic portal documents exist, but no dedicated house-rules feature exists. |
| 114 | Upgrade space during online check-in | S9 | ABSENT | E2, E7 | No guest-facing upgrade or availability flow exists. |
| 115 | Purchase products during online check-in | S9 | ABSENT | E2, E15 | Catalog and retail are staff-side and not exposed in the portal. |
| 116 | Digital registration-card completion | S9 | PARTIAL | E2, [GuestPortalAcknowledgement.php](../apps/api/app/Models/GuestPortalAcknowledgement.php:7) | Inn records document acknowledgements, not complete digital registration cards. |
| 117 | Digital signature | S9/S14 | PARTIAL | E11 | Acknowledgement evidence exists, but no signature capture or signature-provider execution exists. |
| 118 | Pay remaining balance before arrival | S9 | ABSENT | E11, E14 | Inn accepts payment evidence uploads, not online payment settlement. |
| 119 | Direct guest messaging | S9/S14 | ABSENT | E2, E11, E13 | There is no conversation/thread/message endpoint. |
| 120 | Add colleagues to a guest conversation | S9 | ABSENT | E2 | No conversation or participant model exists. |
| 121 | View linked reservations in guest messaging | S9 | ABSENT | E2, E3 | No messaging context view exists. |
| 122 | Add outstanding items to bill at checkout | S9 | ABSENT | E2, E14 | Guests cannot add folio items through the portal. |
| 123 | Schedule departure time | S9/S14 | ABSENT | E2, E3 | No guest departure-scheduling field or route exists. |
| 124 | Edit incorrect billing details | S9 | ABSENT | E2, E14 | No guest billing-edit workflow exists. |
| 125 | Receive final bill automatically by email | S9/S14 | PARTIAL | E13, E14 | Email and folios exist, but no online-checkout settlement email flow exists. |
| 126 | Validate and settle final bill online | S9 | PARTIAL | E11, E14 | Guests can view folios and upload evidence, but cannot settle online. |
| 127 | Guest data access/deletion controls | S9 | ABSENT | E10, E11 | No guest self-service GDPR access, deletion, or export workflow exists. |
| 128 | Guest folio view | S9 | FULL | E2, E11, E14 | Inn exposes a guest folio view. |
| 129 | Pre-arrival preference collection | S7/S9 | FULL | E2, E11, [GuestPortalProfile.php](../apps/api/app/Models/GuestPortalProfile.php:14) | Inn supports pre-arrival preference updates. |
| 130 | Guest document acknowledgement | S9 | FULL | E2, E11 | Inn supports portal document acknowledgement with audit metadata. |
| 131 | Guest payment-evidence upload | S9 | FULL | E2, E11, [GuestPaymentEvidence.php](../apps/api/app/Models/GuestPaymentEvidence.php:9) | Inn supports evidence upload, although not Mews online payment. |

## 3. Housekeeping and Flexkeeping

Flexkeeping is explicitly described by Mews as powered by **Flexkeeping, a Mews company**, rather than being treated as an entirely native Inn/PMS-style module.

| # | Mews advertised feature | Source | Status | Inn evidence | Mismatch |
|---:|---|---|---|---|---|
| 132 | Clean room status | S1/S10 | FULL | E7, E12 | Inn has a clean housekeeping state. |
| 133 | Dirty room status | S1/S10 | FULL | E7, E12 | Inn has a dirty housekeeping state. |
| 134 | In-progress housekeeping status | S1/S10 | FULL | E7, E12 | Inn has an in-progress state. |
| 135 | Inspected room status | S1/S10 | FULL | E7, E12 | Inn has an inspected state. |
| 136 | Out-of-service room status | S1/S10 | FULL | E7, E12 | Inn has an out-of-service state but no full maintenance workflow. |
| 137 | Real-time mobile housekeeping app | S1/S10 | ABSENT | E12 | No dedicated mobile housekeeping client exists. |
| 138 | Instant room-status updates | S1/S10 | PARTIAL | E12 | Inn can mutate status, but lacks Flexkeeping’s mobile/live-dispatch experience. |
| 139 | Automatic updates for late checkouts | S1/S10 | ABSENT | E4, E12 | No late-checkout event updates housekeeping schedules. |
| 140 | Smart cleaning schedules | S10 | ABSENT | E12, E13 | No automated cleaning-schedule engine exists. |
| 141 | Cleaning schemas | S10 | ABSENT | E12 | No cleaning-schema model exists. |
| 142 | Rules based on booking data | S10 | ABSENT | E3, E12 | Reservation data does not generate Flexkeeping cleaning rules. |
| 143 | Rules based on stay length | S10 | ABSENT | E3, E12 | No stay-length housekeeping rule exists. |
| 144 | Rules based on room rate | S10 | ABSENT | E3, E12 | No room-rate housekeeping rule exists. |
| 145 | Rules based on guest preferences | S10 | ABSENT | E10, E12 | Preferences are stored but do not drive cleaning schedules. |
| 146 | Automatic room-attendant assignment | S10 | ABSENT | E12 | Inn has task assignees but no housekeeping assignment engine. |
| 147 | AI task management | S10 | ABSENT | E12, E13 | No AI task manager exists. |
| 148 | Voice task capture | S10 | ABSENT | E12 | No voice capture or speech-processing path exists. |
| 149 | Automatic translation | S10 | ABSENT | E12, E13 | Inn stores languages but does not translate tasks. |
| 150 | Multilingual operations in 200+ languages | S10 | ABSENT | E10, E12 | Guest/staff language fields are not translation support. |
| 151 | Real-time issue reporting | S10 | ABSENT | E12 | No maintenance/issue-reporting endpoint exists. |
| 152 | Minutes-per-room analytics | S10 | ABSENT | E12 | No housekeeping productivity metric exists. |
| 153 | On-time room-readiness analytics | S10 | ABSENT | E12 | No room-readiness KPI exists. |
| 154 | Re-clean analytics | S10 | ABSENT | E12 | No re-clean tracking exists. |
| 155 | Portfolio-wide housekeeping KPIs | S10 | ABSENT | E6, E12 | Inn has no Flexkeeping portfolio KPI layer. |
| 156 | Dynamic schedules from reservation data | S10 | ABSENT | E3, E12 | Reservation changes do not regenerate cleaning schedules. |
| 157 | Staff forecasting from housekeeping demand | S10 | ABSENT | E12 | No staff-load forecast exists. |
| 158 | Automatic update after reservation changes | S10 | ABSENT | E4, E12 | Reservation lifecycle changes do not update housekeeping plans. |
| 159 | Maintenance issue reporting | S10 | ABSENT | E12 | No maintenance issue model/controller exists. |
| 160 | Work-order management | S10 | ABSENT | E12 | No work-order model/controller exists. |
| 161 | Recurring maintenance tasks | S10 | ABSENT | E12, E13 | Generic automation/tasks do not implement recurring maintenance. |
| 162 | Digital checklists | S10 | PARTIAL | E8, E12 | Task templates exist, but no housekeeping checklist/audit model exists. |
| 163 | Centralized task tracking | S10 | FULL | E12 | Inn has operational task records and an operations board. |
| 164 | Task assignment | S10 | FULL | E12 | Inn supports assignees and role-scoped tasks. |
| 165 | Contractor collaboration | S10 | ABSENT | E12 | No contractor identity or collaboration workflow exists. |
| 166 | Contractor progress audit | S10 | ABSENT | E12 | No contractor progress tracking exists. |
| 167 | Special-touch task generation | S10 | PARTIAL | E8, E13 | Inn can generate generic tasks but has no Flexkeeping special-touch service chain. |
| 168 | Real-time ad-hoc guest requests | S10 | PARTIAL | E11, E12 | Portal/pre-arrival data exists, but no guest-request intake-to-task pipeline exists. |
| 169 | Real-time pre-arrival requests | S10 | PARTIAL | E2, E11 | Pre-arrival preferences can be collected but do not automatically create housekeeping tasks. |
| 170 | Linen inventory tracking | S10 | PARTIAL | E15 | Generic stock exists, but no linen-specific housekeeping inventory workflow exists. |
| 171 | Minibar consumption tracking | S10 | PARTIAL | E15 | Generic stock exists, but no minibar workflow exists. |
| 172 | Amenity inventory tracking | S10 | PARTIAL | E15 | Generic catalog/stock exists, but no housekeeping amenity process exists. |
| 173 | Maintenance-supply tracking | S10 | PARTIAL | E15 | Generic stock exists, but no maintenance supply workflow exists. |
| 174 | Lost-and-found management | S10 | ABSENT | E12, E15 | No lost-and-found model or workflow exists. |
| 175 | Digital SOPs and housekeeping audits | S10 | PARTIAL | E8, E12 | Generic task templates exist, but no SOP/audit system exists. |
| 176 | No-code Workflow Builder | S10 | ABSENT | E12, E13 | Mews describes this as beta/forthcoming; Inn has no equivalent builder. |
| 177 | Automated early-checkout service workflows | S10 | ABSENT | E4, E12 | No housekeeping service automation is tied to early checkout. |
| 178 | Automated late-checkout service workflows | S10 | ABSENT | E4, E12 | No housekeeping service automation is tied to late checkout. |
| 179 | Automated baby-cot workflows | S10 | ABSENT | E10, E12 | No child/baby-service task automation exists. |
| 180 | Automated VIP-service workflows | S10 | ABSENT | E10, E12 | No VIP segmentation or service automation exists. |
| 181 | Automated pet-setup workflows | S10 | ABSENT | E10, E12 | No pet-service workflow exists. |
| 182 | Automated birthday/special-touch workflows | S10 | ABSENT | E10, E12 | No event-triggered special-touch workflow exists. |

## 4. Upsells

| # | Mews advertised feature | Source | Status | Inn evidence | Mismatch |
|---:|---|---|---|---|---|
| 183 | Upsells during booking | S6/S11 | ABSENT | E1, E15 | No guest booking flow or upsell engine exists. |
| 184 | Upsells during pre-arrival communication | S11/S19 | ABSENT | E13 | Email automation exists, but offers are not dynamically presented. |
| 185 | Upsells during online check-in | S8/S11 | ABSENT | E2, E11 | Inn has no online check-in purchase flow. |
| 186 | Upsells through kiosk | S11/S13 | ABSENT | No kiosk client | Inn has no kiosk. |
| 187 | Upsells through QR codes | S11 | ABSENT | No QR guest-commerce route | Inn has no QR upsell surface. |
| 188 | Personalized upsell timing | S11/S19 | ABSENT | E10, E13 | No guest-segment/timing offer engine exists. |
| 189 | In-stay purchases synchronized with PMS | S11 | PARTIAL | E15, E14 | Retail sales can link to reservations and folios, but there is no guest-facing upsell journey. |
| 190 | Early check-in upsell | S11/S13 | ABSENT | E3, E11 | No early-check-in product or guest offer exists. |
| 191 | Room upgrades based on live availability | S11/S6 | ABSENT | E5, E7 | Availability checks are internal and cannot sell upgrades. |
| 192 | Room-upgrade offers | S6/S11 | ABSENT | E1, E15 | No upgrade inventory or offer model exists. |
| 193 | Late-checkout offers | S6/S11 | ABSENT | E3, E15 | No late-checkout product or pricing workflow exists. |
| 194 | Parking upsells | S6/S11 | PARTIAL | E7, E15 | Parking can be modeled generically but cannot be offered in a guest journey. |
| 195 | Meeting-space upsells | S4/S11 | PARTIAL | E7, E9 | Proposals/resources exist, but not embedded guest upsells. |
| 196 | In-room treats | S11 | PARTIAL | E15 | Catalog items can represent treats, but no offer/delivery workflow exists. |
| 197 | Food-and-beverage upsells | S11 | PARTIAL | E15 | Retail/catalog exists, but no guest F&B upsell flow exists. |
| 198 | Bike-hire upsells | S11 | PARTIAL | E7, E15 | Generic asset/catalog support exists without guest offer delivery. |
| 199 | Upgraded-Wi-Fi upsells | S11 | ABSENT | E15 | No Wi-Fi service or upsell integration exists. |
| 200 | Airport-transfer upsells | S11 | PARTIAL | E15 | A catalog item could represent one, but no provider or guest flow exists. |
| 201 | Cultural-activity upsells | S11 | PARTIAL | E8, E15 | Programs/catalog items exist, but not Mews-style guest upsells. |
| 202 | Configure upsells in Mews Operations | S19 | ABSENT | E1, E15 | No Upsell model, route, or Filament resource exists. |
| 203 | Exclude offers already present on reservation | S19 | ABSENT | E1, E3, E15 | No conflict-aware offer suppression exists. |
| 204 | Target rate groups | S19 | ABSENT | E3 | No rate-group model exists. |
| 205 | Target space categories | S19 | ABSENT | E7 | Resource categories are not connected to guest offer targeting. |
| 206 | Automated follow-up tasks after an upsell | S19 | PARTIAL | E13 | Generic automation can create tasks, but no upsell event exists. |
| 207 | Automatic upsell revenue tracking | S11 | PARTIAL | E14, E15 | Financial/folio reporting exists, but no upsell attribution exists. |

## 5. Digital Key

Digital Key is a Mews add-on/product requiring compatible lock integrations.

| # | Mews advertised feature | Source | Status | Inn evidence | Mismatch |
|---:|---|---|---|---|---|
| 208 | Bluetooth room key | S12/S20 | ABSENT | E1, E16 | No door-lock or digital-key adapter exists. |
| 209 | Wallet-based room key | S12 | ABSENT | E1, E16 | No Apple/Google wallet key implementation exists. |
| 210 | Wallet key without app download | S12 | ABSENT | E2, E16 | Inn has no wallet credential issuance. |
| 211 | Key delivery after online check-in | S12/S20 | ABSENT | E2, E16 | Inn has neither online check-in nor key issuance. |
| 212 | No-login key access | S12 | ABSENT | E16 | No key-access token is issued to door hardware. |
| 213 | Share key with reservation companions | S12 | ABSENT | E3, E16 | Companion records exist, but no shareable room-key workflow exists. |
| 214 | Guest directions to the room | S12 | ABSENT | E2, E16 | No digital-key directions or property-navigation feature exists. |
| 215 | Mandatory/optional key-activation conditions | S12/S20 | ABSENT | E16 | No digital-key rule engine exists. |
| 216 | Key access from SMS, email, Guest Portal, or kiosk | S12/S20 | ABSENT | E2, E13, E16 | Inn has email/portal access but no key link or lock integration. |
| 217 | Compatible ASSA ABLOY/Salto lock integrations | S12/S20 | ABSENT | E16 | No access-control provider client exists. |

## 6. Mews Kiosk

| # | Mews advertised feature | Source | Status | Inn evidence | Mismatch |
|---:|---|---|---|---|---|
| 218 | iPadOS kiosk | S17 | ABSENT | No kiosk application in repository | Inn has no iPad kiosk client. |
| 219 | Android kiosk | S17 | ABSENT | No kiosk application in repository | Inn has no Android kiosk client. |
| 220 | Contact-free self-check-in | S8/S13/S17 | ABSENT | E2 only provides portal access | Inn does not perform self-check-in. |
| 221 | Contact-free self-checkout | S8/S13/S17 | ABSENT | E2, E3 | Inn has no guest checkout route. |
| 222 | Automatic early-check-in fees | S13/S17/S18 | ABSENT | E3, E14 | No early-check-in product or automated charge exists. |
| 223 | Kiosk product upsells | S13/S17 | ABSENT | No kiosk/upsell model | Inn cannot sell products through a kiosk. |
| 224 | Digital receipts | S17 | ABSENT | E14 | Inn has no kiosk receipt output. |
| 225 | Digital registration cards | S17 | ABSENT | E11 only acknowledges documents | Inn has no kiosk registration-card workflow. |
| 226 | QR-code kiosk setup | S17 | ABSENT | No kiosk setup service | No kiosk provisioning flow exists. |
| 227 | Reservation lookup by last name and confirmation number | S17/S18 | ABSENT | E2, E3 | No kiosk reservation lookup exists. |
| 228 | Reservation lookup by QR code | S17 | ABSENT | No kiosk/QR route | Inn has no QR reservation lookup. |
| 229 | Custom required registration fields | S17 | ABSENT | E10 has fixed form fields | Inn cannot configure kiosk registration schemas. |
| 230 | Custom screensaver | S17 | ABSENT | No kiosk frontend | No kiosk screensaver configuration exists. |
| 231 | Custom kiosk images | S17 | ABSENT | No kiosk frontend/media config | No kiosk image configuration exists. |
| 232 | Light/dark kiosk theme | S17 | ABSENT | No kiosk frontend | No kiosk theme configuration exists. |
| 233 | Check-in grace period | S18 | ABSENT | E3, E4 | No kiosk check-in timing rules exist. |
| 234 | Check-out grace period | S18 | ABSENT | E3, E4 | No kiosk check-out timing rules exist. |
| 235 | Staff mode protected by PIN | S18 | ABSENT | No kiosk client | No kiosk staff-mode security exists. |
| 236 | Rear-camera ID scanning | S18 | ABSENT | E10, E11 | No ID scanner integration exists. |
| 237 | Terminal payment from kiosk | S18 | ABSENT | E14, E16 | Inn has no payment-terminal adapter. |
| 238 | QR guest-device payment from kiosk | S18 | ABSENT | E2, E14 | No guest-device payment flow exists. |
| 239 | Room upgrades at kiosk | S17/S18 | ABSENT | E3, E5 | No kiosk upgrade or live room-availability flow exists. |
| 240 | Key cutting at kiosk | S13/S17 | ABSENT | E16 | No key-cutter/device integration exists. |
| 241 | Animated step-by-step kiosk guidance | S13 | ABSENT | No kiosk frontend | No kiosk guidance UI exists. |

## 7. Virtual Concierge

The Mews virtual-concierge page primarily describes the Mews Guest Portal/Guest Journey. Viqal is separately identified below as a third-party Marketplace integration.

| # | Mews advertised feature | Source | Status | Inn evidence | Mismatch |
|---:|---|---|---|---|---|
| 242 | Guest support through mobile, messaging platforms, or web apps | S14 | PARTIAL | E2, E11 | Inn has a web portal but no guest messaging platform. |
| 243 | Pre-arrival information | S14 | PARTIAL | E2, E11 | Inn collects pre-arrival data but does not deliver a concierge experience. |
| 244 | Personalized recommendations | S14 | ABSENT | E10, E11 | No recommendation engine exists. |
| 245 | Restaurant recommendations | S14 | ABSENT | E8, E15 | No restaurant recommendation or booking flow exists. |
| 246 | Service bookings | S14 | PARTIAL | E8, E1 | Internal service occurrences exist, but guests cannot book them through a concierge. |
| 247 | Check-in details | S14 | PARTIAL | E2, E11 | Portal data/documents exist, but no online check-in flow exists. |
| 248 | Local information | S14 | ABSENT | No local-content model or route | Inn has no concierge knowledge/content layer. |
| 249 | Room-upgrade offers | S14 | ABSENT | E3, E5 | No guest-facing upgrade engine exists. |
| 250 | Service-upgrade offers | S14 | ABSENT | E8, E15 | No guest-facing service-offer engine exists. |
| 251 | Product offers | S14 | ABSENT | E15 | Catalog items are staff-side and not exposed through the portal. |
| 252 | Digital signature in the guest journey | S14 | PARTIAL | E11 | Inn has document acknowledgement, not digital signature execution. |
| 253 | Online guest payments | S14 | ABSENT | E11, E14 | Inn records manual payments or evidence rather than processing online payments. |
| 254 | Real-time messaging to reception | S14 | ABSENT | E2, E13 | No guest-to-reception chat exists. |
| 255 | No-app concierge access | S14 | PARTIAL | E2 | The portal is link-based, but the concierge interaction layer is absent. |
| 256 | Scheduled checkout | S14 | ABSENT | E2, E3 | No guest scheduling route exists. |
| 257 | Automatic settlement email after checkout | S14 | ABSENT | E13, E14 | No automated online-settlement event exists. |
| 258 | Viqal AI WhatsApp concierge | S21 — third-party Marketplace | ABSENT | E16 | Inn has no WhatsApp or Viqal adapter. |
| 259 | Viqal multilingual AI replies | S21 — third-party Marketplace | ABSENT | E13, E16 | Inn has no AI translation or WhatsApp delivery. |
| 260 | Viqal staff handover | S21 — third-party Marketplace | ABSENT | E2, E13 | Inn has no conversation inbox or handover workflow. |
| 261 | Viqal analytics/reporting | S21 — third-party Marketplace | ABSENT | E6, E15 | Inn has no concierge analytics. |
| 262 | Viqal reservation changes through WhatsApp | S21 — third-party Marketplace | ABSENT | E1, E16 | Inn has no messaging-to-reservation command path. |

## 8. Groups, chains, events, and MICE

| # | Mews advertised feature | Source | Status | Inn evidence | Mismatch |
|---:|---|---|---|---|---|
| 263 | One connected platform for every property | S15 | PARTIAL | Property model, E1, E6 | Inn has property scoping but no group-wide operating layer. |
| 264 | Detailed group overview of guests and rates | S15 | PARTIAL | E3, E6, E10 | Inn has guest/property views but no centralized group-rate layer. |
| 265 | Customer migration across properties | S15 | ABSENT | E10 | No migration/import workflow exists. |
| 266 | Company migration across properties | S15 | ABSENT | E15 | Organizations exist, but no migration tool exists. |
| 267 | Reservation migration across properties | S15 | ABSENT | E3, E1 | No cross-property reservation migration exists. |
| 268 | Intelligent property replication | S15 | ABSENT | E1 | No property-template replication service exists. |
| 269 | Remote e-learning onboarding | S15 | ABSENT | No training/e-learning module | Inn has no onboarding-learning product. |
| 270 | Rapid new-property deployment | S15 | ABSENT | E1 | No property-provisioning workflow exists. |
| 271 | Group-wide SSO | S15 | ABSENT | No identity-provider integration | Inn has application authentication but no SSO. |
| 272 | Group-wide passkeys | S15 | ABSENT | No passkey implementation | Inn has no passkey authentication. |
| 273 | Group-wide 2FA | S15 | PARTIAL | Authentication/MFA code exists, but no group-wide identity layer | Inn has application MFA, not Mews enterprise group identity. |
| 274 | Group reservation workflow | S2/S16 | ABSENT | E1, E3 | No first-class group reservation model exists. |
| 275 | Branded group/MICE inquiry engine | S16 | ABSENT | E1, E9 | Inn has no public inquiry engine. |
| 276 | Generate event quotes | S16 | PARTIAL | E9 | Inn has proposals, but not a dedicated event quote product. |
| 277 | Built-in event pricing rules | S16 | ABSENT | E9 | Inn proposal pricing is line-based and lacks event pricing rules. |
| 278 | Event e-signatures | S16 | ABSENT | E9, E16 | No executable e-signature provider exists. |
| 279 | Automated deposit payment links | S16 | PARTIAL | E9, E14 | Inn has deposits and manual payment evidence but no payment links. |
| 280 | Automated signature reminders | S16 | ABSENT | E13 | Generic communications exist, but no signature-reminder workflow exists. |
| 281 | Automated deposit reminders | S16 | PARTIAL | E13, E14 | Deposit reminders exist generically, but not the complete event collection workflow. |
| 282 | Convert confirmed quote into function sheets | S16 | ABSENT | E9, E12 | No function-sheet model exists. |
| 283 | Live updates to sales, kitchen, and service teams | S16 | PARTIAL | E6, [KitchenDashboard.php](../apps/api/app/Filament/Pages/KitchenDashboard.php:17), E13 | Inn has operational projections but no event function-sheet synchronization. |
| 284 | Automated final event invoices | S16 | ABSENT | E9, E14 | Inn has folios and proposals but no event invoice engine. |
| 285 | Final invoice matching the original agreement | S16 | ABSENT | E9, E14 | No contract-to-invoice reconciliation exists. |
| 286 | Meeting/event-space occupancy tracking | S16 | ABSENT | E6, E7 | Inn has reservations and resources but no MICE occupancy analytics. |
| 287 | RevPAM tracking | S16 | ABSENT | E6, E15 | No revenue-per-available-meter metric exists. |
| 288 | Quote-rejection insights | S16 | ABSENT | E9, E15 | No quote-funnel rejection analytics exists. |
| 289 | Event ROI revenue reports | S16 | ABSENT | E15 | No event ROI reporting exists. |
| 290 | Package rooms, catering, and meeting spaces | S1/S16 | PARTIAL | E8, E9, E15 | Inn can represent programs, proposals, and catalog lines but lacks an event package engine. |
| 291 | PMS integration across rooms and event services | S16 | PARTIAL | E3, E8, E9 | Shared internal models reduce duplication, but no dedicated MICE integration exists. |
| 292 | Corporate/event allotments | S2 | ABSENT | E1, E3 | No allotment or corporate block model exists. |
| 293 | Event pickup insights | S2/S16 | ABSENT | E1, E6 | No pickup analytics exists. |
| 294 | Event auto-release of unused rooms | S2 | ABSENT | E1, E3 | No event release rules exist. |
| 295 | Event partner reminders | S2/S16 | ABSENT | E13 | No group/event partner reminder workflow exists. |

## Bottom line

Within this requested scope, Inn is genuinely strong in:

- Internal reservations
- Reservation lifecycle states
- Holds and hold expiry
- Resource allocation
- Conflict prevention
- Resource blocks
- Basic calendar and operations views
- Guest profiles, preferences, history, and merge
- Staff check-in/out
- Folios and manual payment records
- Basic guest portal
- Basic housekeeping statuses
- Operational tasks
- Basic proposals and service occurrences

It is only partial or absent for nearly all Mews differentiators:

- Public booking
- Group reservations and allotments
- Rate plans and restrictions
- Occupancy/dynamic pricing
- Cross-property rate/distribution
- OTA synchronization
- Guest self-check-in/out
- Kiosk
- Digital keys
- Guest messaging
- SMS
- Online payments
- Guest upsells
- Flexkeeping automation
- Maintenance/work orders
- Loyalty and AI guest intelligence
- Event inquiry/quote/function-sheet workflows
- Event billing and analytics
- Enterprise group deployment and identity

The most important correction is that generic Inn resources, programs, catalog items, proposals, and portal routes are not equivalent to Mews’ guest-facing booking, upsell, distribution, Flexkeeping, kiosk, Digital Key, or event products.

## 9. Current Mews OS additions verified on 2026-08-13

The current Mews site now describes five 2026 product innovations under Mews OS. Guest Messaging is described as rolling out globally in August 2026; Automations is described in Mews’ launch material as a later rollout. These are included as announced/rollout-gated capabilities, not silently treated as universally available.

Source: [Introducing Mews OS](https://www.mews.com/en/introducing-mews-os), [Meet the five new Mews products](https://www.mews.com/en/resources/podcasts/meet-the-five-new-mews-products-transforming-how-hotels-work).

| # | Current Mews OS capability | Availability note | Status | Inn evidence | Mismatch |
|---:|---|---|---|---|---|
| 296 | Native Guest Messaging product | Announced; global rollout described for August 2026 | ABSENT | E2, E11, E13 | Inn has email/templates but no native guest inbox. |
| 297 | Unified guest inbox across WhatsApp, SMS, OTA messages, email, and web messaging | Announced/rollout-gated | ABSENT | [CommunicationDeliveryService.php](../apps/api/app/Services/CommunicationDeliveryService.php:18) | No multichannel transport, inbox, or conversation model exists. |
| 298 | Guest-message context linked to reservation, guest profile, and room | Announced/rollout-gated | ABSENT | E2, E3, E10 | Inn has separate portal/reservation records, not a linked message center. |
| 299 | Native Mews Agent for autonomous guest conversations | Announced/rollout-gated | ABSENT | [composer.json](../apps/api/composer.json:8) | No AI agent or conversation execution service exists. |
| 300 | Native Mews Agent can action operational tasks from conversations | Announced/rollout-gated | ABSENT | E12, E13 | Inn can execute internal automation actions, but no guest-message-to-task agent exists. |
| 301 | Mews Automations template-based workflows | Announced; rollout timing is gated | PARTIAL | [AutomationActionExecutor.php](../apps/api/app/Services/Automation/AutomationActionExecutor.php:32) | Inn has internal automation rules, not the Mews Automations product. |
| 302 | Visual workflow builder for Automations | Announced; rollout timing is gated | ABSENT | E13 | No visual workflow builder exists. |
| 303 | Natural-language workflow authoring | Announced; rollout timing is gated | ABSENT | [composer.json](../apps/api/composer.json:8) | No natural-language workflow authoring exists. |
| 304 | Automation actions for upgrades, amenities, and team routing | Announced; rollout timing is gated | PARTIAL | E12, E13, E15 | Internal tasks/automations exist, but no Mews guest-insight-triggered action chain exists. |

## GitHub/OpenAPI PMS addendum

The [live Connector contract](https://api.mews.com/Swagger/connector/swagger.yaml) and [first-party Mews API repository](https://github.com/MewsSystems/open-api-docs) add these PMS-facing feature rows to the earlier catalog. Full source-by-source detail is in the [GitHub/OpenAPI re-audit](mews-vs-inn-github-openapi-audit.md).

| Mews PMS/API feature | Inn status | Exact mismatch |
|---|---|---|
| Guest Portal link generation for Homepage, CheckIn, CheckOut, Chat, and Keys | PARTIAL | Inn has a guest portal, but not Mews’ single-use expiring link types, reservation/customer request contract, or restricted Connector operation. |
| Cancellation-policy catalog and version 2026-07-31 retrieval | ABSENT | No versioned policy catalog, policy applicability, fee extents, offsets, or portfolio/dependency rules. |
| Cancellation-policy add/update/delete | ABSENT | No policy administration API or dependency-aware deletion lifecycle. |
| Resource-category add/update/delete | ABSENT | Inn has internal resources/categories, but no Mews-compatible category CRUD with capacity, localized names, classification, ordering, external ID, and accounting category. |
| Booking Engine promoted services | ABSENT | No public promoted-service operation returning promoted rates, categories, availability, ordering, and prices. |
| Booking Engine Widget and Standalone integration contracts | ABSENT | No Mews.Distributor loader/control API, hosted distributor configuration, or documented deeplink parameters. |
| Loyalty Partner reverse API | ABSENT | No partner-hosted member search, enrollment, membership link/refresh, checkout event, bearer-token rotation, or certification workflow. |
