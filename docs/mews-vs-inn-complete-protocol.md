<!-- Generated from the completed row-level audit worker output. Statuses and claims are preserved verbatim from the worker; verify live Mews URLs before using this as a product contract. -->

This is the baseline Worker C protocol audit, one operation/event at a time, followed by a live GitHub/OpenAPI re-audit addendum. The addendum supersedes stale operation counts where the current first-party contract has moved ahead of the original saved index. Technology by feature is tracked in the [up-to-date technology audit](mews-vs-inn-technology-audit.md).

The baseline pass enumerated:

- 196 Connector API operations, including deprecated ones
- 14 Booking Engine operations
- 12 Channel Manager Mews-side operations
- 7 Channel Manager partner-side operations
- 35 POS operations
- 7 Connector General Webhook events
- 7 Connector Integration Webhook events
- 4 Connector WebSocket events
- 6 POS Webhook events

Total baseline: 281 individually checked rows; later protocol additions bring the saved matrix to 288 baseline rows before the live-contract addendum.

No application files were edited during the original baseline pass; this document now includes the later GitHub/OpenAPI addendum below.

## Evidence keys

Every row’s evidence code expands to exact Inn route/controller/service evidence.

- **E0 — no Mews/provider implementation:** [api.php:45](../apps/api/routes/api.php:45), [composer.json:8](../apps/api/composer.json:8), [IntegrationConnection.php:5](../apps/api/app/Models/IntegrationConnection.php:5), [IntegrationConnectionService.php:8](../apps/api/app/Services/IntegrationConnectionService.php:8)
- **E1 — guests/CRM:** [api.php:53](../apps/api/routes/api.php:53), [GuestController.php:20](../apps/api/app/Http/Controllers/Api/V1/GuestController.php:20), [ExtendedOperationsController.php:285](../apps/api/app/Http/Controllers/Api/V1/ExtendedOperationsController.php:285), [GuestMergeService.php:11](../apps/api/app/Services/GuestMergeService.php:11)
- **E2 — reservations:** [api.php:58](../apps/api/routes/api.php:58), [ReservationController.php:31](../apps/api/app/Http/Controllers/Api/V1/ReservationController.php:31), [ReservationController.php:49](../apps/api/app/Http/Controllers/Api/V1/ReservationController.php:49), [ReservationController.php:217](../apps/api/app/Http/Controllers/Api/V1/ReservationController.php:217), [ReservationService.php:27](../apps/api/app/Services/ReservationService.php:27)
- **E3 — resources/blocks/tasks/services:** [api.php:55](../apps/api/routes/api.php:55), [api.php:56](../apps/api/routes/api.php:56), [api.php:67](../apps/api/routes/api.php:67), [api.php:69](../apps/api/routes/api.php:69), [api.php:80](../apps/api/routes/api.php:80), [AvailabilityService.php:17](../apps/api/app/Services/AvailabilityService.php:17)
- **E4 — finance/folios/payments:** [api.php:71](../apps/api/routes/api.php:71), [FolioController.php:17](../apps/api/app/Http/Controllers/Api/V1/FolioController.php:17), [PaymentController.php:22](../apps/api/app/Http/Controllers/Api/V1/PaymentController.php:22), [PaymentService.php:23](../apps/api/app/Services/PaymentService.php:23), [FinancialReportingService.php:19](../apps/api/app/Services/FinancialReportingService.php:19)
- **E5 — catalog/retail:** [api.php:88](../apps/api/routes/api.php:88), [ExtendedOperationsController.php:33](../apps/api/app/Http/Controllers/Api/V1/ExtendedOperationsController.php:33), [ExtendedOperationsController.php:102](../apps/api/app/Http/Controllers/Api/V1/ExtendedOperationsController.php:102), [RetailPostingService.php:17](../apps/api/app/Services/RetailPostingService.php:17)
- **E6 — organizations/CRM opportunities:** [api.php:96](../apps/api/routes/api.php:96), [ExtendedOperationsController.php:211](../apps/api/app/Http/Controllers/Api/V1/ExtendedOperationsController.php:211), [ExtendedOperationsController.php:218](../apps/api/app/Http/Controllers/Api/V1/ExtendedOperationsController.php:218)
- **E7 — guest portal:** [api.php:32](../apps/api/routes/api.php:32), [GuestPortalController.php:22](../apps/api/app/Http/Controllers/Api/V1/GuestPortalController.php:22), [GuestPortalService.php:21](../apps/api/app/Services/GuestPortalService.php:21), [GuestPortalTokenService.php:17](../apps/api/app/Services/GuestPortalTokenService.php:17)
- **E8 — automation/comms/outbox/calendar:** [AutomationActionExecutor.php:32](../apps/api/app/Services/Automation/AutomationActionExecutor.php:32), [CommunicationDeliveryService.php:18](../apps/api/app/Services/CommunicationDeliveryService.php:18), [OutboxBatchPublisher.php:21](../apps/api/app/Services/Automation/OutboxBatchPublisher.php:21), [CalendarFeedService.php:15](../apps/api/app/Services/CalendarFeedService.php:15)
- **E9 — Inn protocol mechanics:** [api.php:45](../apps/api/routes/api.php:45), [EnsureIdempotentCommand.php:18](../apps/api/app/Http/Middleware/EnsureIdempotentCommand.php:18), [GuestController.php:34](../apps/api/app/Http/Controllers/Api/V1/GuestController.php:34), [PaymentController.php:34](../apps/api/app/Http/Controllers/Api/V1/PaymentController.php:34)

Status: **INT** = internal analogue only; **PART** = partial; **ABS** = absent; **ADAPT** = configuration boundary without provider execution; **DEP** = Mews operation documented as deprecated.

# 1. Mews Connector API

Official inventory: [Mews Connector API operations](https://docs.mews.com/connector-api/operations).

## Accounts

| # | Mews operation | Status | Inn mismatch/evidence |
|---:|---|---|---|
| 1 | [Merge accounts](https://docs.mews.com/connector-api/operations/accounts#merge-accounts) | PART | E1 — only guest merging; no company/general-account merge |
| 2 | [Update accounts](https://docs.mews.com/connector-api/operations/accounts#update-accounts) | PART | E1/E6 — guest update exists; company update absent |
| 3 | [Upload and link file to account](https://docs.mews.com/connector-api/operations/accounts#upload-and-link-file-to-account) | PART | E7 — guest documents/evidence only; no generic account-file contract |
| 4 | [Get all addresses](https://docs.mews.com/connector-api/operations/addresses#get-all-addresses) | ABS | E0/E1 — no address model or route |
| 5 | [Add addresses](https://docs.mews.com/connector-api/operations/addresses#add-addresses) | ABS | E0/E1 — no address model or route |
| 6 | [Update addresses](https://docs.mews.com/connector-api/operations/addresses#update-addresses) | ABS | E0/E1 — no address model or route |
| 7 | [Delete addresses](https://docs.mews.com/connector-api/operations/addresses#delete-addresses) | ABS | E0/E1 — no address model or route |
| 8 | [Get all account notes](https://docs.mews.com/connector-api/operations/accountnotes#get-all-account-notes) | PART | E1 — reservation notes only |
| 9 | [Add account notes](https://docs.mews.com/connector-api/operations/accountnotes#add-account-notes) | PART | E1 — reservation-note route only |
| 10 | [Update account notes](https://docs.mews.com/connector-api/operations/accountnotes#update-account-notes) | ABS | E1 — no account-note update route |
| 11 | [Delete account notes](https://docs.mews.com/connector-api/operations/accountnotes#delete-account-notes) | ABS | E1 — no account-note delete route |

## Billing automations

| # | Mews operation | Status | Inn mismatch/evidence |
|---:|---|---|---|
| 12 | [Get all billing automations](https://docs.mews.com/connector-api/operations/billingautomations#get-all-billing-automations) | PART | E8 — reservation milestone automation, not Mews billing automation |
| 13 | [Add billing automations](https://docs.mews.com/connector-api/operations/billingautomations#add-billing-automations) | PART | E8 — internal automation actions only |
| 14 | [Update billing automations](https://docs.mews.com/connector-api/operations/billingautomations#update-billing-automations) | PART | E8 — no Mews billing schedule semantics |
| 15 | [Update billing automation assignments](https://docs.mews.com/connector-api/operations/billingautomations#update-billing-automation-assignments) | ABS | E0 — no assignment model |
| 16 | [Delete billing automations](https://docs.mews.com/connector-api/operations/billingautomations#delete-billing-automations) | PART | E8 — internal rules only, no Mews billing automation |

## Configuration

| # | Mews operation | Status | Inn mismatch/evidence |
|---:|---|---|---|
| 17 | [Get configuration](https://docs.mews.com/connector-api/operations/configuration#get-configuration) | PART | E0 — property/tenant configuration exists, not Mews configuration |
| 18 | [Get all countries](https://docs.mews.com/connector-api/operations/countries#get-all-countries) | ABS | E0 — no country catalog API |
| 19 | [Get all currencies](https://docs.mews.com/connector-api/operations/currencies#get-all-currencies) | PART | E4 — currency fields/FX exist, no Mews currency catalog |
| 20 | [Get all tax environments](https://docs.mews.com/connector-api/operations/taxenvironments#get-all-tax-environments) | ABS | E0 — no tax-environment model |
| 21 | [Get all taxations](https://docs.mews.com/connector-api/operations/taxations#get-all-taxations) | PART | E4 — tax arithmetic exists, no taxation catalog |
| 22 | [Get all languages](https://docs.mews.com/connector-api/operations/languages#get-all-languages) | PART | E1 — guest language field only |
| 23 | [Get language texts](https://docs.mews.com/connector-api/operations/languages#get-language-texts) | ABS | E0 — no language-text API |
| 24 | [Get image URLs](https://docs.mews.com/connector-api/operations/images#get-image-urls) | ABS | E0 — private local documents are not Mews image URLs |

## Customers and identity documents

| # | Mews operation | Status | Inn mismatch/evidence |
|---:|---|---|---|
| 25 | [Get all customers](https://docs.mews.com/connector-api/operations/customers#get-all-customers) | INT | E1 — `/v1/guests`; different entity and response contract |
| 26 | [Search customers](https://docs.mews.com/connector-api/operations/customers#search-customers) | INT | E1 — guest search by name/email/phone |
| 27 | [Get customers relationships](https://docs.mews.com/connector-api/operations/customers#get-customers-relationships) | PART | E1 — reservation companions, no customer-relationship model |
| 28 | [Get customers open items](https://docs.mews.com/connector-api/operations/customers#get-customers-open-items) **DEP** | PART | E4 — folio/payment balances, not Mews open-items operation |
| 29 | [Add customer](https://docs.mews.com/connector-api/operations/customers#add-customer) | INT | E1 — `/v1/guests` POST |
| 30 | [Update customer](https://docs.mews.com/connector-api/operations/customers#update-customer) | INT | E1 — guest update only |
| 31 | [Merge customers](https://docs.mews.com/connector-api/operations/customers#merge-customers) **DEP** | PART | E1 — guest-only merge |
| 32 | [Add customer file](https://docs.mews.com/connector-api/operations/customers#add-customer-file) | PART | E7 — guest documents/payment evidence, no customer-file API |
| 33 | [Get all identity documents](https://docs.mews.com/connector-api/operations/identitydocuments#get-all-identity-documents) | ABS | E0 — no identity-document model |
| 34 | [Adds identity documents](https://docs.mews.com/connector-api/operations/identitydocuments#add-identity-documents) | ABS | E0 — acknowledgements are not identity documents |
| 35 | [Update identity documents](https://docs.mews.com/connector-api/operations/identitydocuments#update-identity-documents) | ABS | E0 — no identity-document route |
| 36 | [Delete identity documents](https://docs.mews.com/connector-api/operations/identitydocuments#delete-identity-documents) | ABS | E0 — no identity-document route |
| 37 | [Clear identity documents](https://docs.mews.com/connector-api/operations/identitydocuments#clear-identity-documents) | ABS | E0 — no identity-document route |

## Device integration

| # | Mews operation | Status | Inn mismatch/evidence |
|---:|---|---|---|
| 38 | [Get all devices](https://docs.mews.com/connector-api/operations/devices#get-all-devices) | ABS | E0 — no device model |
| 39 | [Get all commands](https://docs.mews.com/connector-api/operations/commands#get-all-commands) | ABS | E0 — no command lifecycle |
| 40 | [Get all commands by IDs](https://docs.mews.com/connector-api/operations/commands#get-all-commands-by-ids) | ABS | E0 — no command identifiers/model |
| 41 | [Get all fiscal-machine commands](https://docs.mews.com/connector-api/operations/commands#get-all-fiscal-machine-commands) | ABS | E0 — no fiscal-device integration |
| 42 | [Add printer command](https://docs.mews.com/connector-api/operations/commands#add-printer-command) | ABS | E0 — no printer command route |
| 43 | [Add key-cutter command](https://docs.mews.com/connector-api/operations/commands#add-key-cutter-command) | ABS | E0 — no key-cutter integration |
| 44 | [Add payment command](https://docs.mews.com/connector-api/operations/commands#add-payment-command) | ABS | E0 — no payment-terminal command |
| 45 | [Update command](https://docs.mews.com/connector-api/operations/commands#update-command) | ABS | E0 — no command state model |

## Enterprises, resources, and tasks

| # | Mews operation | Status | Inn mismatch/evidence |
|---:|---|---|---|
| 46 | [Get all enterprises](https://docs.mews.com/connector-api/operations/enterprises#get-all-enterprises) | PART | E0 — tenants/properties exist, no Mews enterprise/access-token semantics |
| 47 | [Get all companies](https://docs.mews.com/connector-api/operations/companies#get-all-companies) | PART | E6 — organizations exist, not Mews company API |
| 48 | [Add company](https://docs.mews.com/connector-api/operations/companies#add-company) | PART | E6 — organization creation supports company type |
| 49 | [Update company](https://docs.mews.com/connector-api/operations/companies#update-company) | ABS | E6 — no organization update route |
| 50 | [Delete companies](https://docs.mews.com/connector-api/operations/companies#delete-companies) | ABS | E6 — no organization delete route |
| 51 | [Get all company contracts](https://docs.mews.com/connector-api/operations/companycontracts#get-all-company-contracts) | ABS | E0 — no contract model |
| 52 | [Add company contracts](https://docs.mews.com/connector-api/operations/companycontracts#add-company-contracts) | ABS | E0 — no contract model |
| 53 | [Update company contracts](https://docs.mews.com/connector-api/operations/companycontracts#update-company-contracts) | ABS | E0 — no contract model |
| 54 | [Delete company contracts](https://docs.mews.com/connector-api/operations/companycontracts#delete-company-contracts) | ABS | E0 — no contract model |
| 55 | [Get all departments](https://docs.mews.com/connector-api/operations/departments#get-all-departments) | ABS | E0 — no department model/API |
| 56 | [Get all counters](https://docs.mews.com/connector-api/operations/counters#get-all-counters) | ABS | E0 — no counter model/API |
| 57 | [Get all outlets](https://docs.mews.com/connector-api/operations/outlets#get-all-outlets) | ABS | E5 — stock locations are not Mews outlets |
| 58 | [Get all resources](https://docs.mews.com/connector-api/operations/resources#get-all-resources) | INT | E3 — `/v1/resources` |
| 59 | [Get resources’ occupancy state](https://docs.mews.com/connector-api/operations/resources#get-resources-occupancy-state) | PART | E3 — availability checks/projections, not Mews occupancy operation |
| 60 | [Update resources](https://docs.mews.com/connector-api/operations/resources#update-resources) | INT | E3 — resource CRUD exists internally |
| 61 | [Get all resource blocks](https://docs.mews.com/connector-api/operations/resourceblocks#get-all-resource-blocks) | INT | E3 — `/v1/resource-blocks` |
| 62 | [Add resource block](https://docs.mews.com/connector-api/operations/resourceblocks#add-resource-block) | INT | E3 — internal resource-block POST |
| 63 | [Delete resource blocks](https://docs.mews.com/connector-api/operations/resourceblocks#delete-resource-blocks) | INT | E3 — internal resource-block DELETE |
| 64 | [Add task](https://docs.mews.com/connector-api/operations/tasks#add-task) | INT | E3 — `/v1/tasks` |
| 65 | [Close task](https://docs.mews.com/connector-api/operations/tasks#close-tasks) | INT | E3 — task state update exists, no Mews contract |
| 66 | [Get all tasks](https://docs.mews.com/connector-api/operations/tasks#get-all-tasks) | INT | E3 — internal operational-task listing |
| 67 | [Get all resource categories](https://docs.mews.com/connector-api/operations/resourcecategories#get-all-resource-categories) | PART | E3 — model used by resources, no dedicated public API |
| 68 | [Get all resource-category assignments](https://docs.mews.com/connector-api/operations/resourcecategories#get-all-resource-category-assignments) | PART | E3 — allocation/category relations, not Mews assignment operation |
| 69 | [Get all resource-category image assignments](https://docs.mews.com/connector-api/operations/resourcecategories#get-all-resource-category-image-assignments) | ABS | E0 — no image-assignment model |
| 70 | [Get all resource features](https://docs.mews.com/connector-api/operations/resourcefeatures#get-all-resource-features) | PART | E3 — resource attributes/capabilities only |
| 71 | [Get all resource-feature assignments](https://docs.mews.com/connector-api/operations/resourcefeatures#get-all-resource-feature-assignments) | PART | E3 — capability matching only |

## Exports

| # | Mews operation | Status | Inn mismatch/evidence |
|---:|---|---|---|
| 72 | [Get all exports](https://docs.mews.com/connector-api/operations/exports#get-all-exports) | PART | E4 — ReportExport/CSV/PDF exports, not restricted Mews exports |
| 73 | [Add export](https://docs.mews.com/connector-api/operations/exports#add-export) | PART | E4 — internal report export only |

## Finance, bills, payments, and ledger

| # | Mews operation | Status | Inn mismatch/evidence |
|---:|---|---|---|
| 74 | [Get all exchange rates](https://docs.mews.com/connector-api/operations/exchangerates#get-all-exchange-rates) | INT | E4 — ExchangeRate/ExchangeRateService internal FX |
| 75 | [Get all cashiers](https://docs.mews.com/connector-api/operations/cashiers#get-all-cashiers) | ABS | E0 — no cashier model |
| 76 | [Get all cashier transactions](https://docs.mews.com/connector-api/operations/cashiertransactions#get-all-cashier-transactions) | PART | E4 — payments/retail exist, no cashier ledger |
| 77 | [Get all accounting categories](https://docs.mews.com/connector-api/operations/accountingcategories#get-all-accounting-categories) | ABS | E0 — no accounting-category API |
| 78 | [Get all accounting items](https://docs.mews.com/connector-api/operations/accountingitems#get-all-accounting-items) **DEP** | PART | E4 — folio lines/payment lines are only analogues |
| 79 | [Update accounting items](https://docs.mews.com/connector-api/operations/accountingitems#update-accounting-items) | ABS | E4 — no accounting-item update |
| 80 | [Get all bills](https://docs.mews.com/connector-api/operations/bills#get-all-bills) | PART | E4 — folios, not Mews bills |
| 81 | [Add bill](https://docs.mews.com/connector-api/operations/bills#add-bill) | PART | E4 — folio creation, not Mews bill |
| 82 | [Update bills](https://docs.mews.com/connector-api/operations/bills#update-bills) | ABS | E4 — no bill assignment/update |
| 83 | [Delete bill](https://docs.mews.com/connector-api/operations/bills#delete-bill) | ABS | E4 — no bill deletion |
| 84 | [Close bill](https://docs.mews.com/connector-api/operations/bills#close-bill) | INT | E4 — folio close/reopen |
| 85 | [Get bill PDF](https://docs.mews.com/connector-api/operations/bills#get-bill-pdf) | PART | E4 — generated documents/PDFs, no bill-PDF contract |
| 86 | [Get all outlet items](https://docs.mews.com/connector-api/operations/outletitems#get-all-outlet-items) | PART | E5/E4 — retail-sale lines, not Mews outlet items |
| 87 | [Get all credit cards](https://docs.mews.com/connector-api/operations/creditcards#get-all-credit-cards) | ABS | E0 — no stored-card model |
| 88 | [Charge credit card](https://docs.mews.com/connector-api/operations/creditcards#charge-credit-card) | ABS | E4 — PaymentService never calls a processor |
| 89 | [Add tokenized credit card](https://docs.mews.com/connector-api/operations/creditcards#add-tokenized-credit-card) | ABS | E0 — no card vault/tokenization |
| 90 | [Disable gateway credit card](https://docs.mews.com/connector-api/operations/creditcards#disable-gateway-credit-card) | ABS | E0 — no gateway-card lifecycle |
| 91 | [Add credit-card payment](https://docs.mews.com/connector-api/operations/payments#add-credit-card-payment) **DEP** | PART | E4 — manual payment record only |
| 92 | [Add external payment](https://docs.mews.com/connector-api/operations/payments#add-external-payment) | PART | E4 — provider reference can be recorded; no provider execution |
| 93 | [Add alternative payment](https://docs.mews.com/connector-api/operations/payments#add-alternative-payment) | PART | E4 — scalar/manual payment methods only |
| 94 | [Get all payments](https://docs.mews.com/connector-api/operations/payments#get-all-payments) | INT | E4 — `/v1/payments` |
| 95 | [Get all payment requests](https://docs.mews.com/connector-api/operations/paymentrequests#get-all-payment-requests) | PART | E4 — deposits are not Mews payment requests |
| 96 | [Add payment requests](https://docs.mews.com/connector-api/operations/paymentrequests#add-payment-requests) | PART | E4 — deposit creation only |
| 97 | [Cancel payment requests](https://docs.mews.com/connector-api/operations/paymentrequests#cancel-payment-requests) | PART | E4 — deposit waive is not request cancellation |
| 98 | [Add payment-method request](https://docs.mews.com/connector-api/operations/paymentmethodrequests#add-payment-method-request) | ABS | E0 — no payment-method request |
| 99 | [Add outlet bills](https://docs.mews.com/connector-api/operations/outletbills#add-outlet-bills) | PART | E5/E4 — retail sale/folio posting only |
| 100 | [Get all order items](https://docs.mews.com/connector-api/operations/orderitems#get-all-order-items) | PART | E5/E4 — retail lines/folio lines only |
| 101 | [Cancel order items](https://docs.mews.com/connector-api/operations/orderitems#cancel-order-items) | PART | E4 — folio reversal is not order-item cancellation |
| 102 | [Refund payment](https://docs.mews.com/connector-api/operations/payments#refund-payment) | PART | E4 — internal reversal, no external refund |
| 103 | [Add billing-automation payment plan](https://docs.mews.com/connector-api/operations/paymentplans#add-billing-automation-payment-plan) | PART | E4/E8 — deposit schedules, not Mews payment plans |
| 104 | [Get all ledger balances](https://docs.mews.com/connector-api/operations/ledgerbalances#get-all-ledger-balances) | PART | E4 — folio/finance summaries, no daily ledger API |

## Loyalty

| # | Mews operation | Status | Inn mismatch/evidence |
|---:|---|---|---|
| 105 | [Get all loyalty programs](https://docs.mews.com/connector-api/operations/loyaltyprograms#get-all-loyalty-programs) | ABS | E0 — no loyalty model |
| 106 | [Add loyalty programs](https://docs.mews.com/connector-api/operations/loyaltyprograms#add-loyalty-programs) | ABS | E0 — no loyalty model |
| 107 | [Update loyalty programs](https://docs.mews.com/connector-api/operations/loyaltyprograms#update-loyalty-programs) | ABS | E0 — no loyalty model |
| 108 | [Delete loyalty programs](https://docs.mews.com/connector-api/operations/loyaltyprograms#delete-loyalty-programs) | ABS | E0 — no loyalty model |
| 109 | [Get all loyalty memberships](https://docs.mews.com/connector-api/operations/loyaltymemberships#get-all-loyalty-memberships) | ABS | E0 — no membership program |
| 110 | [Add loyalty memberships](https://docs.mews.com/connector-api/operations/loyaltymemberships#add-loyalty-memberships) | ABS | E0 — no membership program |
| 111 | [Update loyalty memberships](https://docs.mews.com/connector-api/operations/loyaltymemberships#update-loyalty-memberships) | ABS | E0 — no membership program |
| 112 | [Delete loyalty memberships](https://docs.mews.com/connector-api/operations/loyaltymemberships#delete-loyalty-memberships) | ABS | E0 — no membership program |
| 113 | [Get all loyalty tiers](https://docs.mews.com/connector-api/operations/loyaltytiers#get-all-loyalty-tiers) | ABS | E0 — no loyalty tiers |
| 114 | [Add loyalty tiers](https://docs.mews.com/connector-api/operations/loyaltytiers#add-loyalty-tiers) | ABS | E0 — no loyalty tiers |
| 115 | [Update loyalty tiers](https://docs.mews.com/connector-api/operations/loyaltytiers#update-loyalty-tiers) | ABS | E0 — no loyalty tiers |
| 116 | [Delete loyalty tiers](https://docs.mews.com/connector-api/operations/loyaltytiers#delete-loyalty-tiers) | ABS | E0 — no loyalty tiers |

## Messaging

| # | Mews operation | Status | Inn mismatch/evidence |
|---:|---|---|---|
| 117 | [Get all message threads](https://docs.mews.com/connector-api/operations/messagethreads#get-all-message-threads) | ABS | E8 — no conversational thread model/API |
| 118 | [Add message thread](https://docs.mews.com/connector-api/operations/messagethreads#add-message-thread) | ABS | E8 — no thread creation |
| 119 | [Get all messages](https://docs.mews.com/connector-api/operations/messages#get-all-messages) | PART | E8 — email Communication records, no Mews message API |
| 120 | [Add messages](https://docs.mews.com/connector-api/operations/messages#add-messages) | PART | E8 — email delivery only; SMS/WhatsApp absent |

## Reservations

| # | Mews operation | Status | Inn mismatch/evidence |
|---:|---|---|---|
| 121 | [Get all reservations, 2023-06-06](https://docs.mews.com/connector-api/operations/reservations#get-all-reservations-ver-2023-06-06) | INT | E2 — `/v1/reservations` |
| 122 | [Get all reservations, 2017-04-12](https://docs.mews.com/connector-api/operations/reservations#get-all-reservations-ver-2017-04-12) **DEP** | ABS | E0 — no legacy Mews contract |
| 123 | [Get all reservation items](https://docs.mews.com/connector-api/operations/reservations#get-all-reservation-items) **DEP** | PART | E2/E4 — allocations/folio/services are different entities |
| 124 | [Price reservations](https://docs.mews.com/connector-api/operations/reservations#price-reservations) | PART | E2 — proposal price snapshots, no Mews price operation |
| 125 | [Add reservations](https://docs.mews.com/connector-api/operations/reservations#add-reservations) | PART | E2 — reservation creation, no Mews group contract |
| 126 | [Update reservations](https://docs.mews.com/connector-api/operations/reservations#update-reservations) | INT | E2 — internal update route |
| 127 | [Confirm reservations](https://docs.mews.com/connector-api/operations/reservations#confirm-reservations) | INT | E2 — `/confirm` transition |
| 128 | [Start reservation](https://docs.mews.com/connector-api/operations/reservations#start-reservation) | INT | E2 — check-in transition |
| 129 | [Process reservation](https://docs.mews.com/connector-api/operations/reservations#process-reservation) | INT | E2 — checkout/process transition |
| 130 | [Cancel reservation](https://docs.mews.com/connector-api/operations/reservations#cancel-reservation) | INT | E2 — cancellation transition |
| 131 | [Update reservation customer](https://docs.mews.com/connector-api/operations/reservations#update-reservation-customer) | PART | E2/E1 — primary/companion guest updates, no Mews contract |
| 132 | [Update reservation interval](https://docs.mews.com/connector-api/operations/reservations#update-reservation-interval) | INT | E2 — start/end update with internal conflict checking |
| 133 | [Add reservation companion](https://docs.mews.com/connector-api/operations/reservations#add-reservation-companion) | PART | E2/E1 — `guest_ids`/reservation_guests, no dedicated companion API |
| 134 | [Delete reservation companion](https://docs.mews.com/connector-api/operations/reservations#delete-reservation-companion) | PART | E2/E1 — no dedicated delete route |
| 135 | [Add reservation product](https://docs.mews.com/connector-api/operations/reservations#add-reservation-product) | PART | E2/E4/E5 — catalog/folio/service occurrence analogues |
| 136 | [Get all source assignments, 2024-09-20](https://docs.mews.com/connector-api/operations/sourceassignments#get-all-source-assignments-ver-2024-09-20) | ABS | E0 — no reservation-source assignment model |
| 137 | [Get all source assignments](https://docs.mews.com/connector-api/operations/sourceassignments#get-all-source-assignments) | ABS | E0 — no reservation-source assignment model |
| 138 | [Get all sources](https://docs.mews.com/connector-api/operations/sources#get-all-sources) | ABS | E0 — no source catalog |
| 139 | [Get all reservation groups](https://docs.mews.com/connector-api/operations/reservationgroups#get-all-reservation-groups) | ABS | E2 — no first-class reservation-group model |
| 140 | [Get reservations Channel Manager details](https://docs.mews.com/connector-api/operations/reservations#get-reservations-channel-manager-details) | ABS | E0 — no Channel Manager client |
| 141 | [Get all routing rules](https://docs.mews.com/connector-api/operations/routingrules#get-all-routing-rules) **DEP** | ABS | E0 — no routing-rule model |
| 142 | [Add routing rules](https://docs.mews.com/connector-api/operations/routingrules#add-routing-rules) **DEP** | ABS | E0 — no routing-rule model |
| 143 | [Update routing rules](https://docs.mews.com/connector-api/operations/routingrules#update-routing-rules) **DEP** | ABS | E0 — no routing-rule model |
| 144 | [Delete routing rules](https://docs.mews.com/connector-api/operations/routingrules#delete-routing-rules) **DEP** | ABS | E0 — no routing-rule model |

## Service orders and services

| # | Mews operation | Status | Inn mismatch/evidence |
|---:|---|---|---|
| 145 | [Get all product service orders](https://docs.mews.com/connector-api/operations/productserviceorders#get-all-product-service-orders) | PART | E2/E5 — service occurrences/catalog, not Mews product service orders |
| 146 | [Get all service-order notes](https://docs.mews.com/connector-api/operations/serviceordernotes#get-all-service-order-notes) | PART | E2 — reservation notes only |
| 147 | [Add service-order notes](https://docs.mews.com/connector-api/operations/serviceordernotes#add-service-order-notes) | PART | E2 — reservation-note route only |
| 148 | [Update service-order notes](https://docs.mews.com/connector-api/operations/serviceordernotes#update-service-order-notes) | ABS | E2 — no service-order note update |
| 149 | [Delete service-order notes](https://docs.mews.com/connector-api/operations/serviceordernotes#delete-service-order-notes) | ABS | E2 — no service-order note delete |
| 150 | [Get all services](https://docs.mews.com/connector-api/operations/services#get-all-services) | PART | E2/E5 — programs/service occurrences, no Mews service catalog |
| 151 | [Get service availability, 2024-01-22](https://docs.mews.com/connector-api/operations/services#get-service-availability-ver-2024-01-22) | PART | E3 — internal availability checks, not restricted Mews metrics |
| 152 | [Get service availability](https://docs.mews.com/connector-api/operations/services#get-service-availability) | PART | E3 — internal calendar/capacity only |
| 153 | [Update service availability](https://docs.mews.com/connector-api/operations/services#update-service-availability) | ABS | E0 — no service-availability mutation |
| 154 | [Get all availability blocks](https://docs.mews.com/connector-api/operations/availabilityblocks#get-all-availability-blocks) | PART | E3 — resource blocks are a different domain |
| 155 | [Add availability blocks](https://docs.mews.com/connector-api/operations/availabilityblocks#add-availability-blocks) | PART | E3 — resource-block creation only |
| 156 | [Update availability blocks](https://docs.mews.com/connector-api/operations/availabilityblocks#update-availability-blocks) | PART | E3 — resource-block update only |
| 157 | [Delete availability blocks](https://docs.mews.com/connector-api/operations/availabilityblocks#delete-availability-blocks) | PART | E3 — resource-block deletion only |
| 158 | [Get all availability adjustments](https://docs.mews.com/connector-api/operations/availabilityadjustments#get-all-availability-adjustments) | ABS | E0 — no availability-adjustment model |
| 159 | [Get all rules](https://docs.mews.com/connector-api/operations/rules#get-all-rules) | ABS | E0 — no rate/restriction rules engine |
| 160 | [Get all business segments](https://docs.mews.com/connector-api/operations/businesssegments#get-all-business-segments) | ABS | E0 — no business-segment model |
| 161 | [Get all rates](https://docs.mews.com/connector-api/operations/rates#get-all-rates) | ABS | E0 — no RatePlan/rate model |
| 162 | [Add rates](https://docs.mews.com/connector-api/operations/rates#add-rates) | ABS | E0 — no rate engine |
| 163 | [Set rates](https://docs.mews.com/connector-api/operations/rates#set-rates) | ABS | E0 — no rate engine |
| 164 | [Delete rates](https://docs.mews.com/connector-api/operations/rates#delete-rates) | ABS | E0 — no rate engine |
| 165 | [Update rate capacity-offset pricing](https://docs.mews.com/connector-api/operations/rates#update-rate-capacity-offset-pricing) | ABS | E0 — no capacity-offset pricing |
| 166 | [Get rate pricing](https://docs.mews.com/connector-api/operations/rates#get-rate-pricing) | PART | E2/E5 — proposal/catalog prices only |
| 167 | [Update rate price](https://docs.mews.com/connector-api/operations/rates#update-rate-price) | ABS | E0 — no rate-price mutation |
| 168 | [Get all rate groups](https://docs.mews.com/connector-api/operations/rategroups#get-all-rate-groups) | ABS | E0 — no rate groups |
| 169 | [Get all restrictions](https://docs.mews.com/connector-api/operations/restrictions#get-all-restrictions) | ABS | E0 — no restriction engine |
| 170 | [Set restrictions](https://docs.mews.com/connector-api/operations/restrictions#set-restrictions) | ABS | E0 — no restriction engine |
| 171 | [Clear restrictions](https://docs.mews.com/connector-api/operations/restrictions#clear-restrictions) | ABS | E0 — no restriction engine |
| 172 | [Add order](https://docs.mews.com/connector-api/operations/orders#add-order) | PART | E5 — retail sales are narrower than Mews orders |
| 173 | [Get all companionships](https://docs.mews.com/connector-api/operations/companionships#get-all-companionships) | PART | E1/E2 — reservation guests, no Mews companionship operation |
| 174 | [Get all resource access tokens](https://docs.mews.com/connector-api/operations/resourceaccesstokens#get-all-resource-access-tokens) | PART | E7 — guest portal tokens have different semantics |
| 175 | [Add resource access tokens](https://docs.mews.com/connector-api/operations/resourceaccesstokens#add-resource-access-tokens) | ABS | E7 — no resource access-token API |
| 176 | [Update resource access tokens](https://docs.mews.com/connector-api/operations/resourceaccesstokens#update-resource-access-tokens) | ABS | E7 — no resource access-token API |
| 177 | [Delete resource access tokens](https://docs.mews.com/connector-api/operations/resourceaccesstokens#delete-resource-access-tokens) | ABS | E7 — no resource access-token API |
| 178 | [Get all vouchers](https://docs.mews.com/connector-api/operations/vouchers#get-all-vouchers) | ABS | E0 — no voucher model |
| 179 | [Add vouchers](https://docs.mews.com/connector-api/operations/vouchers#add-vouchers) | ABS | E0 — no voucher model |
| 180 | [Update vouchers](https://docs.mews.com/connector-api/operations/vouchers#update-vouchers) | ABS | E0 — no voucher model |
| 181 | [Delete vouchers](https://docs.mews.com/connector-api/operations/vouchers#delete-vouchers) | ABS | E0 — no voucher model |
| 182 | [Get all voucher codes](https://docs.mews.com/connector-api/operations/vouchercodes#get-all-voucher-codes) | ABS | E0 — no voucher-code model |
| 183 | [Add voucher codes](https://docs.mews.com/connector-api/operations/vouchercodes#add-voucher-codes) | ABS | E0 — no voucher-code model |
| 184 | [Delete voucher codes](https://docs.mews.com/connector-api/operations/vouchercodes#delete-voucher-codes) | ABS | E0 — no voucher-code model |
| 185 | [Get all age categories](https://docs.mews.com/connector-api/operations/agecategories#get-all-age-categories) | ABS | E0 — no age-category model |
| 186 | [Get all cancellation policies](https://docs.mews.com/connector-api/operations/cancellationpolicies#get-all-cancellation-policies) | ABS | E0 — no cancellation-policy engine |
| 187 | [Get cancellation policies by reservations](https://docs.mews.com/connector-api/operations/cancellationpolicies#get-cancellation-policies-by-reservations) | ABS | E0 — no cancellation-policy engine |
| 188 | [Get cancellation policies by rates](https://docs.mews.com/connector-api/operations/cancellationpolicies#get-cancellation-policies-by-rates) | ABS | E0 — no rate/policy engine |
| 189 | [Get all products](https://docs.mews.com/connector-api/operations/products#get-all-products) | PART | E5 — CatalogItem is an internal subset |
| 190 | [Delete products](https://docs.mews.com/connector-api/operations/products#delete-products) | ABS | E5 — no catalog delete route |
| 191 | [Get product pricing](https://docs.mews.com/connector-api/operations/products#get-product-pricing) | PART | E5 — catalog/proposal prices only |
| 192 | [Update product pricing](https://docs.mews.com/connector-api/operations/products#update-product-pricing) | ABS | E5 — no catalog update-price route |
| 193 | [Get all product categories](https://docs.mews.com/connector-api/operations/productcategories#get-all-product-categories) | PART | E5 — retail/extra/service types, no Mews categories |
| 194 | [Get all service overbooking limits](https://docs.mews.com/connector-api/operations/serviceoverbookinglimits#get-all-service-overbooking-limits) | ABS | E0 — no overbooking-limit model |
| 195 | [Set service overbooking limits](https://docs.mews.com/connector-api/operations/serviceoverbookinglimits#set-service-overbooking-limits) | ABS | E0 — no overbooking-limit mutation |
| 196 | [Clear service overbooking limits](https://docs.mews.com/connector-api/operations/serviceoverbookinglimits#clear-service-overbooking-limits) | ABS | E0 — no overbooking-limit mutation |

# 2. Booking Engine API

Official inventory: [Booking Engine API operations](https://docs.mews.com/booking-engine-guide/booking-engine-api/operations).

| # | Mews operation | Status | Inn mismatch/evidence |
|---:|---|---|---|
| 197 | [Get availability](https://docs.mews.com/booking-engine-guide/booking-engine-api/operations/hotels#get-availability) | ABS | E0 — no public availability endpoint |
| 198 | [Get availability blocks](https://docs.mews.com/booking-engine-guide/booking-engine-api/operations/availability-blocks#get-availability-blocks) | ABS | E0 — no public availability-block endpoint |
| 199 | [Get configuration](https://docs.mews.com/booking-engine-guide/booking-engine-api/operations/configuration#get-configuration) | ABS | E0 — no booking-engine instance model |
| 200 | [Get hotels](https://docs.mews.com/booking-engine-guide/booking-engine-api/operations/hotels#get-hotels) | PART | E0 — internal property listing is staff-authenticated, not Booking Engine hotel data |
| 201 | [Get payment configuration](https://docs.mews.com/booking-engine-guide/booking-engine-api/operations/hotels#get-payment-configuration) | ABS | E0 — no public payment configuration |
| 202 | [Get payment cards](https://docs.mews.com/booking-engine-guide/booking-engine-api/operations/payment-cards#get-payment-cards) | ABS | E4 — no card model |
| 203 | [Authorize payment card](https://docs.mews.com/booking-engine-guide/booking-engine-api/operations/payment-cards#authorize-payment-card) | ABS | E4 — manual ledger only |
| 204 | [Get reservations pricing](https://docs.mews.com/booking-engine-guide/booking-engine-api/operations/reservations#get-reservations-pricing) | PART | E2/E5 — proposal/catalog pricing, no public quote contract |
| 205 | [Get reservation price](https://docs.mews.com/booking-engine-guide/booking-engine-api/operations/reservations#get-reservation-price) | PART | E2 — internal immutable proposal totals, no Booking Engine operation |
| 206 | [Create reservation group](https://docs.mews.com/booking-engine-guide/booking-engine-api/operations/reservation-groups#create-reservation-group) | PART | E2 — internal reservation POST, no public group booking |
| 207 | [Get reservation group](https://docs.mews.com/booking-engine-guide/booking-engine-api/operations/reservation-groups#get-reservation-group) | PART | E2 — reservation lookup, no reservation-group entity |
| 208 | [Get services availability](https://docs.mews.com/booking-engine-guide/booking-engine-api/operations/services#get-services-availability) | PART | E3 — internal service/resource capacity only |
| 209 | [Get services pricing](https://docs.mews.com/booking-engine-guide/booking-engine-api/operations/services#get-services-pricing) | PART | E5 — catalog/program pricing only |
| 210 | [Validate voucher](https://docs.mews.com/booking-engine-guide/booking-engine-api/operations/vouchers#validate-voucher) | ABS | E0 — no voucher engine |

# 3. Channel Manager API

The Channel Manager API has two separate surfaces: Mews-side operations and partner-side operations. Both are required for a complete implementation. Official references: [Mews-side operations](https://docs.mews.com/channel-manager-api/mews-operations) and [Channel Manager-side operations](https://docs.mews.com/channel-manager-api/channel-manager-operations).

## Mews-side operations

| # | Mews operation | Status | Inn mismatch/evidence |
|---:|---|---|---|
| 211 | [Get properties](https://docs.mews.com/channel-manager-api/mews-operations/configuration#get-properties) | PART | E0 — `/v1/properties` exists, but no Channel Manager connection tokens/details |
| 212 | [Get configuration](https://docs.mews.com/channel-manager-api/mews-operations/configuration#get-configuration) | ABS | E0 — no CHM configuration endpoint |
| 213 | [Get channels](https://docs.mews.com/channel-manager-api/mews-operations/configuration#get-channels) | ABS | E0 — no channel catalog/mapping |
| 214 | [Set inventory](https://docs.mews.com/channel-manager-api/mews-operations/inventory#set-inventory) | ABS | E0 — no rate-plan/space-type mapping |
| 215 | [Request ARI update](https://docs.mews.com/channel-manager-api/mews-operations/inventory#request-ari-update) | ABS | E0 — no ARI request flow |
| 216 | [Confirm availability update](https://docs.mews.com/channel-manager-api/mews-operations/inventory#confirm-availability-update) | ABS | E0 — no CHM confirmation |
| 217 | [Confirm price update](https://docs.mews.com/channel-manager-api/mews-operations/inventory#confirm-price-update) | ABS | E0 — no CHM confirmation |
| 218 | [Confirm restriction update](https://docs.mews.com/channel-manager-api/mews-operations/inventory) | ABS | E0 — no restriction update contract |
| 219 | [Confirm availability-block synchronization](https://docs.mews.com/channel-manager-api/mews-operations/inventory) | ABS | E0 — no availability-block synchronization |
| 220 | [Process availability block](https://docs.mews.com/channel-manager-api/mews-operations/availabilityblock#process-availability-block) | ABS | E0 — internal resource blocks are not CHM ingestion |
| 221 | [Process group](https://docs.mews.com/channel-manager-api/mews-operations/reservations#process-group) | ABS | E0 — no inbound channel reservation endpoint |
| 222 | [Confirm reservations-group synchronization](https://docs.mews.com/channel-manager-api/mews-operations/reservations#confirm-group-confirmation) | ABS | E0 — no CHM confirmation |

## Channel Manager-side operations

| # | Mews operation | Status | Inn mismatch/evidence |
|---:|---|---|---|
| 223 | [Update prices](https://docs.mews.com/channel-manager-api/channel-manager-operations/inventory#update-prices) | ABS | E0 — no endpoint receiving Mews price pushes |
| 224 | [Update availability](https://docs.mews.com/channel-manager-api/channel-manager-operations/inventory#update-availability) | ABS | E0 — no endpoint receiving availability pushes |
| 225 | [Update restrictions](https://docs.mews.com/channel-manager-api/channel-manager-operations/inventory#update-restrictions) | ABS | E0 — no restriction receiver |
| 226 | [Process availability block](https://docs.mews.com/channel-manager-api/channel-manager-operations/availabilityblock) | ABS | E0 — no CHM availability-block receiver |
| 227 | [Process group](https://docs.mews.com/channel-manager-api/channel-manager-operations/reservations#process-group) | ABS | E0 — no inbound reservation-group receiver |
| 228 | [Confirm booking](https://docs.mews.com/channel-manager-api/channel-manager-operations/reservations#confirm-booking) | ABS | E0 — no booking confirmation endpoint |
| 229 | [Change notification](https://docs.mews.com/channel-manager-api/channel-manager-operations/notifications#change-notification) | ABS | E0 — no connection-change notification endpoint |

Mews explicitly requires a two-way implementation: the Mews side processes reservations and the Channel Manager side receives inventory updates. Inn implements neither side. [Channel Manager API overview](https://docs.mews.com/channel-manager-api).

# 4. POS API

Official inventory: [Mews POS API operations](https://docs.mews.com/pos-api/operations). The POS API is documented as active and subject to change: [Mews POS API](https://docs.mews.com/pos-api).

| # | Mews operation | Status | Inn mismatch/evidence |
|---:|---|---|---|
| 230 | [Get invoices](https://docs.mews.com/pos-api/operations/invoices) | ABS | E4 — no invoice model; generated documents are not POS invoices |
| 231 | [Get registers](https://docs.mews.com/pos-api/operations/registers) | ABS | E0 — no register model |
| 232 | [Get register](https://docs.mews.com/pos-api/operations/registers) | ABS | E0 — no register model |
| 233 | [Get products](https://docs.mews.com/pos-api/operations/products) | PART | E5 — CatalogItem is not POS product API |
| 234 | [Get product](https://docs.mews.com/pos-api/operations/products) | PART | E5 — no POS product endpoint |
| 235 | [Get promo codes](https://docs.mews.com/pos-api/operations/promo-codes) | ABS | E0 — no promo-code model |
| 236 | [Get modifier sets](https://docs.mews.com/pos-api/operations/modifier-sets) | ABS | E0 — no modifier-set model |
| 237 | [Get modifier set](https://docs.mews.com/pos-api/operations/modifier-sets) | ABS | E0 — no modifier-set model |
| 238 | [Get invoice item](https://docs.mews.com/pos-api/operations/invoice-items) | PART | E4 — FolioLine is only a financial analogue |
| 239 | [Get customers](https://docs.mews.com/pos-api/operations/customers) | PART | E1 — Guest is not POS Customer contract |
| 240 | [Create customer](https://docs.mews.com/pos-api/operations/customers) | PART | E1 — guest creation, not POS customer creation |
| 241 | [Get tables](https://docs.mews.com/pos-api/operations/tables) | ABS | E0 — no table model |
| 242 | [Get outlets](https://docs.mews.com/pos-api/operations/outlets) | ABS | E0 — no POS outlet model |
| 243 | [Get orders](https://docs.mews.com/pos-api/operations/orders) | PART | E5 — RetailSale is a narrow analogue |
| 244 | [Create order](https://docs.mews.com/pos-api/operations/orders) | PART | E5 — retail sale creation, no POS order lifecycle |
| 245 | [Get order](https://docs.mews.com/pos-api/operations/orders) | PART | E5 — no POS order entity |
| 246 | [Update order](https://docs.mews.com/pos-api/operations/orders) | ABS | E5 — no retail-sale update route |
| 247 | [Update booking](https://docs.mews.com/pos-api/operations/bookings) | PART | E2 — reservation update, not POS booking |
| 248 | [Get bookings](https://docs.mews.com/pos-api/operations/bookings) | PART | E2 — reservation listing, not POS booking listing |
| 249 | [Create booking](https://docs.mews.com/pos-api/operations/bookings) | PART | E2 — reservation creation, not POS booking |
| 250 | [Get webhook endpoints](https://docs.mews.com/pos-api/operations/webhook-endpoints) | ABS | E0 — no webhook-endpoint model |
| 251 | [Create webhook endpoint](https://docs.mews.com/pos-api/operations/webhook-endpoints) | ABS | E0 — no webhook registration |
| 252 | [Update webhook endpoint](https://docs.mews.com/pos-api/operations/webhook-endpoints) | ABS | E0 — no webhook subscription update |
| 253 | [Get areas](https://docs.mews.com/pos-api/operations/areas) | ABS | E0 — no POS area model |
| 254 | [Get revenue centers](https://docs.mews.com/pos-api/operations/revenue-centers) | ABS | E0 — no revenue-center model |
| 255 | [Get payment methods](https://docs.mews.com/pos-api/operations/payment-methods) | PART | E4 — internal scalar/manual methods, no POS catalog |
| 256 | [Get product bundles](https://docs.mews.com/pos-api/operations/product-bundles) | ABS | E0 — no product-bundle model |
| 257 | [Create a payment](https://docs.mews.com/pos-api/operations/payments) | PART | E4 — manual local payment record; no POS payment API |
| 258 | [Get the health check](https://docs.mews.com/pos-api/operations/system) | ABS | E0 — no POS health endpoint |
| 259 | [Get menus](https://docs.mews.com/pos-api/operations/menus) | ABS | E0 — no menu model |
| 260 | [Get menu details](https://docs.mews.com/pos-api/operations/menus) | ABS | E0 — no menu model |
| 261 | [Get space code](https://docs.mews.com/pos-api/operations/space-codes) | ABS | E7 — guest portal tokens are unrelated |
| 262 | [Create a short-lived integration token](https://docs.mews.com/pos-api/operations/shortlived-integration-tokens) | PART | E7 — portal token exists, not POS integration token |
| 263 | [Get tax profiles](https://docs.mews.com/pos-api/operations/tax-profiles) | PART | E4 — tax amounts exist, no POS tax-profile API |
| 264 | [Get tax profile](https://docs.mews.com/pos-api/operations/tax-profiles) | PART | E4 — tax amounts exist, no POS tax-profile API |

The POS Models page contains schemas rather than executable operations, so it is not counted as an additional operation inventory.

# 5. Connector General Webhooks

Official source: [Mews General Webhooks](https://docs.mews.com/connector-api/events/wh-general).

| # | Event | Status | Inn mismatch/evidence |
|---:|---|---|---|
| 265 | `ServiceOrderUpdated` | ABS | E0/E8 — no inbound Mews webhook controller |
| 266 | `ResourceUpdated` | ABS | E0/E8 — internal outbox is outbound/internal, not Mews ingress |
| 267 | `MessageAdded` | ABS | E0/E8 — no inbound message webhook |
| 268 | `ResourceBlockUpdated` | ABS | E0/E8 — no webhook receiver |
| 269 | `CustomerAdded` | ABS | E0/E8 — no webhook receiver |
| 270 | `CustomerUpdated` | ABS | E0/E8 — no webhook receiver |
| 271 | `PaymentUpdated` | ABS | E0/E8 — no webhook receiver |

Mews General Webhooks require an externally accessible POST endpoint, quick acknowledgement, asynchronous processing, and event-specific fetching. Inn has no such endpoint.

# 6. Connector Integration Webhooks

Official source: [Mews Integration Webhooks](https://docs.mews.com/connector-api/events/wh-integration).

| # | Event | Status | Inn mismatch/evidence |
|---:|---|---|---|
| 272 | `IntegrationCreated` | ABS | E0 — no integration lifecycle webhook |
| 273 | `IntegrationEnabled` | ABS | E0 — no integration lifecycle webhook |
| 274 | `IntegrationDisabled` | ABS | E0 — no integration lifecycle webhook |
| 275 | `IntegrationCanceled` | ABS | E0 — no integration lifecycle webhook |
| 276 | `IntegrationReinstated` | ABS | E0 — no integration lifecycle webhook |
| 277 | `IntegrationDeleted` | ABS | E0 — no integration lifecycle webhook |
| 278 | `IntegrationApiKeyCreated` | ABS | E0 — no Mews AccessToken lifecycle |

# 7. Connector WebSockets

Official source: [Mews WebSockets](https://docs.mews.com/connector-api/websockets).

| # | Event | Status | Inn mismatch/evidence |
|---:|---|---|---|
| 279 | `DeviceCommand` | ABS | E0 — no WebSocket client/server or device-command consumer |
| 280 | `Reservation` | ABS | E0 — no real-time Mews reservation consumer |
| 281 | `Resource` | ABS | E0 — no real-time Mews resource consumer |
| 282 | `PriceUpdate` | ABS | E0 — no rate-price event consumer |

Mews WebSockets use a separate `wss://` domain, cookie authentication, and a persistent event connection. Inn has no WebSocket implementation.

# 8. POS Webhooks

Official source: [Mews POS Webhooks](https://docs.mews.com/pos-api/events/webhooks).

| # | Event | Status | Inn mismatch/evidence |
|---:|---|---|---|
| 283 | `booking.status.updated` | ABS | E0 — no POS webhook endpoint |
| 284 | `order.state.updated` | ABS | E0 — no POS order/event consumer |
| 285 | `order.status.updated` | ABS | E0 — no POS order/event consumer |
| 286 | `product.availability.updated` | ABS | E0 — no POS product event consumer |
| 287 | `order.total.updated` | ABS | E0 — no POS order event consumer |
| 288 | `orders.payments.added` | ABS | E0 — no POS payment event consumer |

POS Webhooks use outgoing HTTP POST requests and `X-Signature` HMAC-SHA256 validation. Inn has no webhook endpoint, signature verifier, or event consumer.

# 9. Authentication and protocol mechanics

| Mews surface | Official requirement | Inn status | Exact mismatch |
|---|---|---|---|
| Connector authentication | `ClientToken`, `AccessToken`, and `Client` in every POST body | ABS | Inn uses Sanctum bearer authentication on internal routes: E9 |
| Connector request format | `POST [PlatformAddress]/api/connector/v1/{Resource}/{Action}`, JSON body | ABS | Inn uses Laravel REST-style `/api/v1/...` routes: E9 |
| Connector optional localization | `LanguageCode` and `CultureCode` together | ABS | No equivalent protocol fields |
| Booking Engine authentication | Registered `Client` value in every request | ABS | No public Booking Engine endpoint |
| Booking Engine request format | `POST [ApiBaseUrl]/api/distributor/v1/{Resource}/{Operation}` | ABS | No public distributor/booking-engine route |
| Channel Manager authentication | `clientToken` plus `connectionToken` | ABS | No CHM client or connection-token handling |
| POS authentication | Bearer API key | ABS | Inn Sanctum is an internal user/session API, not POS bearer API-key integration |
| POS response contract | JSON:API media type and JSON:API resource structures | ABS | Inn Laravel JSON resources, no JSON:API POS contract |
| POS webhook authentication | HMAC-SHA256 `X-Signature` | ABS | No webhook receiver/signature verifier |
| WebSocket authentication | Connector tokens supplied as cookies to a separate WebSocket host | ABS | No WebSocket client/server |
| Connector cursor pagination | `Limitation.Cursor`, `Limitation.Count`, maximum count 1,000, response cursor | ABS | Inn uses Laravel `paginate`, with local `per_page` limits such as 100: E9 |
| POS pagination | `page[size]`, `page[before]`, `page[after]`, maximum 1,000 | ABS | No POS client or JSON:API pagination |
| Connector throttling | Production guidance: 200 requests per AccessToken per 30 seconds; handle `429` and `Retry-After` | ABS | Inn has inbound `throttle:120,1`; no outbound Mews rate budget/retry client |
| POS demo throttling | 200 requests per token per 15-minute window | ABS | No POS client or throttle handling |
| Booking Engine throttling | `429` plus `Retry-After`; anti-scraping protection | ABS | No Booking Engine client |
| Connector environments | Demo and Production; separate platform and WebSocket addresses | ABS | No Mews demo/prod configuration |
| POS environments | Demo and Production POS bases; linked demo PMS/POS property | ABS | No POS demo account, fixture, or linked-property harness |
| Booking Engine environments | Separate Demo and Production client registration | ABS | No registered Booking Engine client |
| Connector certification | Mews certification workflow before production credentials | ABS | No Mews certification workflow or contract test suite |
| Channel Manager certification | Inventory push, reservation, error, invalid reservation, and inactive connection tests | ABS | No CHM endpoint implementation or certification harness |
| POS development environment | Mews provides POS demo web/Android app and linked PMS property | ABS | No POS app/client or end-to-end fixture |
| Webhook delivery | Externally reachable POST endpoint, timely response, retry on failure | ABS | No inbound webhook route |
| Integration lifecycle | Mews can emit integration enable/disable/cancel/reinstate/delete/key events | ABS | Inn integration records only store local configuration |
| External provider execution | Mews integration requires actual API client behavior | ADAPT | [IntegrationConnectionService.php](../apps/api/app/Services/IntegrationConnectionService.php:11) validates and stores configuration only |

Official protocol references: [Connector authentication](https://docs.mews.com/connector-api/guidelines/authentication), [Connector requests](https://docs.mews.com/connector-api/guidelines/requests), [Connector pagination](https://docs.mews.com/connector-api/guidelines/pagination), [Connector environments](https://docs.mews.com/connector-api/guidelines/environments), [Booking Engine authentication](https://docs.mews.com/booking-engine-guide/booking-engine-api/guidelines/authentication), [Booking Engine requests](https://docs.mews.com/booking-engine-guide/booking-engine-api/guidelines/requests), [Channel Manager authentication](https://docs.mews.com/channel-manager-api/guidelines/authentication), and [POS environments](https://docs.mews.com/pos-api/guidelines/environments).

## Final Worker C conclusion

Inn has internal analogues for guests, reservations, resources, blocks, tasks, service occurrences, folios, deposits, manual payments, catalog items, retail sales, proposals, CRM organizations, and internal reports.

It does not implement:

- The Mews Connector API contract
- The Mews Booking Engine API
- Either side of the Channel Manager API
- The Mews POS API
- Connector General Webhooks
- Connector Integration Webhooks
- Connector WebSockets
- POS Webhooks
- Mews authentication/token lifecycle
- Mews cursor pagination
- Mews JSON:API POS contract
- Mews external-provider rate-limit handling
- Mews demo/certification workflows

The generic Inn integration model is therefore **ADAPT-only**, not an integration implementation.

## 10. Live GitHub/OpenAPI re-audit addendum

The attached Mews research links were checked against the [current first-party Mews API repository](https://github.com/MewsSystems/open-api-docs) and the [live Connector Swagger](https://api.mews.com/Swagger/connector/swagger.yaml). The live contract parses as OpenAPI 3.0.4 with 205 paths and 205 operations. The earlier saved Connector rows did not include the following current operations:

| Current Mews operation | Endpoint | Inn status | Exact gap |
|---|---|---|---|
| Get all preauthorizations by customers | `POST /api/connector/v1/preauthorizations/getAllByCustomers` | ABSENT | No customer preauthorization model, card reference, state, or provider operation. |
| Get current cancellation policies, version 2026-07-31 | `POST /api/connector/v1/cancellationPolicies/getAll/2026-07-31` | ABSENT | No versioned cancellation-policy catalog or retrieval contract. |
| Add cancellation policies | `POST /api/connector/v1/cancellationPolicies/add` | ABSENT | No policy creation, applicability, fee-extent, offset, or external-ID contract. |
| Update cancellation policies | `POST /api/connector/v1/cancellationPolicies/update` | ABSENT | Reservation cancellation state is not policy mutation. |
| Delete cancellation policies | `POST /api/connector/v1/cancellationPolicies/delete` | ABSENT | No dependency-aware policy deletion lifecycle. |
| Add resource categories | `POST /api/connector/v1/resourceCategories/add` | ABSENT | No Mews-compatible category creation with capacity, localization, classification, ordering, or accounting fields. |
| Update resource categories | `POST /api/connector/v1/resourceCategories/update` | ABSENT | Internal resource editing is not the Connector category update contract. |
| Delete resource categories | `POST /api/connector/v1/resourceCategories/delete` | ABSENT | No dependency-aware category deletion. |
| Generate Guest Portal links | `POST /api/connector/v1/reservations/generateGuestPortalLinks` | PARTIAL | Inn has a portal, but not Mews’ single-use, expiring Homepage/CheckIn/CheckOut/Chat/Keys link contract. |

The repository’s restrictions `add` and `delete` pages are discontinued and are not counted as current features; Mews’ current model is `set`/`clear` restrictions. Full request/response detail and source links are in the [GitHub/OpenAPI re-audit](mews-vs-inn-github-openapi-audit.md).

### Booking Engine and integration surfaces missed by the baseline rows

| Current Mews surface | Inn status | Feature-level gap |
|---|---|---|
| Booking Engine `Get promoted services` | ABSENT | No public operation returning promoted services, promoted rates, resource categories, availability, ordering, and prices. |
| Booking Engine Widget loader and `Mews.Distributor` control API | ABSENT | No CDN loader, configuration-ID embedding, iframe/overlay lifecycle, date/language/currency/voucher/occupancy controls, or tracking controls. |
| Booking Engine Standalone/deeplinks | ABSENT | No hosted distributor route or `mewsStart`, `mewsEnd`, voucher, room, route, hotel, city, language, and currency deeplink contract. |
| Mews Payments Checkout | ABSENT | No embeddable checkout for Connector payment requests or direct enterprise/amount/currency capture, 3DS, Apple Pay, Google Pay, iDEAL, SEPA, callbacks, or payment-method storage. |
| Loyalty Partner reverse API | ABSENT | No partner-hosted member search/enrollment/link/unlink/list-refresh API, bearer-token rotation, checkout event, or Mews certification workflow. |
| POS JSON:API mechanics | ABSENT | No `application/vnd.api+json`, relationships/includes, sparse fieldsets, cursor links, or `Idempotency-Key` implementation. |
| POS restricted use cases | ABSENT | No Mews digital ordering, restaurant table booking, inventory synchronization, or associated payment/room-charge workflows. |

These are additional API surfaces, not substitutes for the 288 baseline operation/event rows. See the [GitHub/OpenAPI re-audit](mews-vs-inn-github-openapi-audit.md) for the exact first-party source files and the implementation-oriented repository cross-checks.
