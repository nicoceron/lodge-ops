<!-- Generated from the completed row-level audit worker output. Statuses and claims are preserved verbatim from the worker; verify live Mews URLs before using this as a product contract. -->

# Audit Worker B — Finance, Payments, Accounting, AR, Revenue, BI

This is the complete row-level finance/revenue pass against the current Inn checkout.

- No application files were edited during the original finance pass; this document now includes the later GitHub/OpenAPI addendum below. Technology by feature is tracked in the [up-to-date technology audit](mews-vs-inn-technology-audit.md).
- Focused finance tests: **46/46 passed**
- Full API tests: **210/210 passed**
- PHPStan: **0 errors**
- Worktree remains dirty from another parallel audit task.

Status meanings:

- **FULL:** Concrete internal implementation exists.
- **PARTIAL:** A subset exists, but important Mews behavior is missing.
- **ABSENT:** No executable implementation found.
- **ADAPTER-ONLY:** Configuration/storage boundary exists without provider execution.

## 1. Payment methods

| Mews capability | Source | Status | Inn evidence | Mismatch |
|---|---|---:|---|---|
| Card payments | [Mews payments](https://www.mews.com/en/products/payments) | PARTIAL | [StorePaymentRequest.php:13](../apps/api/app/Http/Requests/StorePaymentRequest.php:13) | Inn records a card manually; no gateway, authorization, capture, vault, or processor |
| Cash payments | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | FULL | [StorePaymentRequest.php:13](../apps/api/app/Http/Requests/StorePaymentRequest.php:13) | No Mews cashier, register, receipt, or cashier-transaction model |
| Wire transfer | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | PARTIAL | [PaymentService.php:23](../apps/api/app/Services/PaymentService.php:23) | Manual record only; no automated wire collection or matching |
| BACS | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | No BACS-specific method or provider |
| Cheque | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [StorePaymentRequest.php:13](../apps/api/app/Http/Requests/StorePaymentRequest.php:13) | No cheque method |
| Invoice payment | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [api.php:71](../apps/api/routes/api.php:71) | No invoice model or invoice payment type |
| Prepayment | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | PARTIAL | [DepositService.php:16](../apps/api/app/Services/DepositService.php:16) | Inn creates due deposits; it does not automatically collect them |
| Direct debit | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | No direct-debit provider |
| SEPA Direct Debit | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | No mandate, IBAN, or SEPA execution |
| iDEAL | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | No redirect payment flow |
| Apple Pay | [Mews payments](https://www.mews.com/en/products/payments) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | No wallet integration |
| Google Pay | [Mews payments](https://www.mews.com/en/products/payments) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | No wallet integration |
| PayPal | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | No PayPal execution |
| Bancontact | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | No local-payment adapter |
| TWINT | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | No TWINT adapter |
| Reka | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | No Reka adapter |
| Gift card | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | No gift-card balance or redemption |
| Loyalty points | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | No loyalty ledger |
| Loyalty card | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | No loyalty-card payment |
| Chèque-Vacances | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | No supported method |
| Voucher payment | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | No voucher validation or redemption |
| POS Dining & Spa Reward | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [api.php:88](../apps/api/routes/api.php:88) | No POS reward payment |
| Deposit by check | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [Deposit.php:13](../apps/api/app/Models/Deposit.php:13) | Deposit has no payment-method subtype |
| Deposit by cash | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | PARTIAL | [DepositService.php:16](../apps/api/app/Services/DepositService.php:16) | Deposit can be manually associated with a cash payment; no dedicated deposit rail |
| Deposit by credit card | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | PARTIAL | [PaymentService.php:82](../apps/api/app/Services/PaymentService.php:82) | Manual association only; no card charge |
| Deposit by wire | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | PARTIAL | [PaymentService.php:82](../apps/api/app/Services/PaymentService.php:82) | Manual association only; no wire matching |
| Bad-debt payment code | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [Payment.php:16](../apps/api/app/Models/Payment.php:16) | No structured external payment classification |
| Exchange-rate-difference payment code | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [Payment.php:16](../apps/api/app/Models/Payment.php:16) | FX exists separately, not as an accounting payment type |
| Complimentary payment code | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [Payment.php:16](../apps/api/app/Models/Payment.php:16) | No complimentary settlement type |
| Reseller payment code | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [Payment.php:16](../apps/api/app/Models/Payment.php:16) | No reseller settlement type |
| Exchange-rounding payment code | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [Payment.php:16](../apps/api/app/Models/Payment.php:16) | No accounting classification |
| Barter payment code | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [Payment.php:16](../apps/api/app/Models/Payment.php:16) | No barter method |
| Commission payment code | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | PARTIAL | [CommissionAccrual.php:14](../apps/api/app/Models/CommissionAccrual.php:14) | Commission accrual exists, not commission payment classification |
| Bank-charge payment code | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [Payment.php:16](../apps/api/app/Models/Payment.php:16) | No bank-charge accounting type |
| Cross-settlement payment code | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [Payment.php:16](../apps/api/app/Models/Payment.php:16) | No cross-settlement workflow |
| Card-check payment code | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [Payment.php:16](../apps/api/app/Models/Payment.php:16) | No card-check operation |
| Payment-hub redirection | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | No redirect/payment-hub flow |
| Virtual-card payment | [Mews payments](https://www.mews.com/en/products/payments) | ABSENT | [Payment.php:16](../apps/api/app/Models/Payment.php:16) | No virtual-card detection or charging |
| Card-brand processing | [Connector credit cards](https://docs.mews.com/connector-api/operations/creditcards.md) | ABSENT | [Payment.php:16](../apps/api/app/Models/Payment.php:16) | No card model; Mews documents Visa, Mastercard, Amex, Discover, JCB, UnionPay, Maestro, VPay, RuPay, Dankort, Mir, Verve, Troy, PostFinance, Giro, Bancomat, Carte Bleue, Eftpos, EPS, Interac, Isracard, Meps, Nets, and Bancontact |

## 2. Payment processing and settlement

| Mews capability | Source | Status | Inn evidence | Mismatch |
|---|---|---:|---|---|
| Embedded payment gateway | [Mews payments](https://www.mews.com/en/products/payments) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | No processor SDK or gateway client |
| Payment authorization | [Booking Engine authorization](https://docs.mews.com/booking-engine-guide/booking-engine-api/use-cases/payment-card-authorization.md) | ABSENT | [PaymentController.php:39](../apps/api/app/Http/Controllers/Api/V1/PaymentController.php:39) | `captured` is only an input flag; no authorization request |
| Payment capture | [Mews payments](https://www.mews.com/en/products/payments) | ABSENT | [PaymentService.php:23](../apps/api/app/Services/PaymentService.php:23) | “Capture” calls local reconciliation; it never captures a card |
| Preauthorization | [Connector preauthorizations](https://docs.mews.com/connector-api/operations/preauthorizations.md) | ABSENT | [Payment.php:16](../apps/api/app/Models/Payment.php:16) | No preauthorization model or endpoint |
| Post-authorization charge | [Mews tokenization](https://www.mews.com/en/products/tokenization) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | No stored card or post-auth charge |
| Manual payment record | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | FULL | [PaymentService.php:23](../apps/api/app/Services/PaymentService.php:23) | Internal ledger record, not provider execution |
| Provider reference and deduplication | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | FULL | [PaymentService.php:28](../apps/api/app/Services/PaymentService.php:28) | Internal deduplication only |
| Payment reconciliation state | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | PARTIAL | [PaymentService.php:71](../apps/api/app/Services/PaymentService.php:71) | Staff changes pending to succeeded; no processor settlement |
| External refund execution | [Connector refund payment](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [PaymentService.php:124](../apps/api/app/Services/PaymentService.php:124) | No external refund call |
| Internal reversal/refund ledger entry | [Connector refund payment](https://docs.mews.com/connector-api/operations/payments.md) | FULL | [FolioService.php:76](../apps/api/app/Services/FolioService.php:76) | Correct internal reversal, but not a processor refund |
| Chargeback notifications | [Mews payments](https://www.mews.com/en/products/payments) | ABSENT | [api.php:45](../apps/api/routes/api.php:45) | No dispute or chargeback model |
| Chargeback reversal | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [PaymentStatus.php:5](../apps/api/app/Enums/PaymentStatus.php:5) | No chargeback state |
| Recurring card payments | [Mews payments](https://www.mews.com/en/products/payments) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | No recurring scheduler or stored payment method |
| Recurring SEPA payments | [Mews payments](https://www.mews.com/en/products/payments) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | No SEPA mandate or collection |
| Automated prepayments | [Mews payments](https://www.mews.com/en/products/payments) | PARTIAL | [DepositService.php:16](../apps/api/app/Services/DepositService.php:16) | Deposit schedules exist; payment collection remains manual |
| No-show payment protection | [Mews payments](https://www.mews.com/en/products/payments) | PARTIAL | [ReservationStatus.php:5](../apps/api/app/Enums/ReservationStatus.php:5) | No-show status exists, but no automated charge/protection |
| In-stay card-not-present purchases | [Mews payments](https://www.mews.com/en/products/payments) | ABSENT | [api.php:88](../apps/api/routes/api.php:88) | Retail charges can post to a reservation, but no card-not-present charge |
| Virtual-card detection | [Mews payments](https://www.mews.com/en/products/payments) | ABSENT | [Payment.php:16](../apps/api/app/Models/Payment.php:16) | No virtual-card field or detector |
| Tap-to-pay | [Mews payments](https://www.mews.com/en/products/payments) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | No NFC/payment-terminal integration |
| Tipping | [Mews payments](https://www.mews.com/en/products/payments) | ABSENT | [StorePaymentRequest.php:9](../apps/api/app/Http/Requests/StorePaymentRequest.php:9) | No tip field or tip allocation |
| 3-D Secure / 2FA payment flow | [Mews multicurrency](https://www.mews.com/en/products/multicurrency) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | Application MFA is not payment 3DS |
| PCI/PSD2 payment handling | [Mews tokenization](https://www.mews.com/en/products/tokenization) | ABSENT | [Payment.php:16](../apps/api/app/Models/Payment.php:16) | No card-data environment or processor compliance implementation |
| Scheduled payment requests | [Connector payment requests](https://docs.mews.com/connector-api/operations/paymentrequests.md) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | No payment-request model or route |
| Payment-method request link | [Connector payment method requests](https://docs.mews.com/connector-api/operations/paymentmethodrequests.md) | ABSENT | [api.php:32](../apps/api/routes/api.php:32) | Guest portal has payment evidence, not payment-method collection |
| Payment plans | [Connector payment plans](https://docs.mews.com/connector-api/operations/paymentplans.md) | PARTIAL | [ReservationConfirmationProvisioner.php:49](../apps/api/app/Services/ReservationConfirmationProvisioner.php:49) | Deposit milestones exist; no Mews billing-automation payment plan |
| Payment-origin tracking | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | PARTIAL | [Payment.php:16](../apps/api/app/Models/Payment.php:16) | Metadata/provider fields exist; no structured booking-engine, terminal, POS, or payment-request origins |

## 3. Tokenization

| Mews capability | Source | Status | Inn evidence | Mismatch |
|---|---|---:|---|---|
| Tokenize card at booking | [Mews tokenization](https://www.mews.com/en/products/tokenization) | ABSENT | [api.php:58](../apps/api/routes/api.php:58) | No public booking engine or card token |
| Store token on guest profile | [Mews tokenization](https://www.mews.com/en/products/tokenization) | ABSENT | [Guest.php:1](../apps/api/app/Models/Guest.php:1) | No tokenized payment relation |
| Reuse token across properties | [Mews tokenization](https://www.mews.com/en/products/tokenization) | ABSENT | [Payment.php:16](../apps/api/app/Models/Payment.php:16) | Tenant payment records contain no reusable card token |
| Tokenized in-stay charges | [Mews tokenization](https://www.mews.com/en/products/tokenization) | ABSENT | [api.php:88](../apps/api/routes/api.php:88) | Retail posting is not tokenized charging |
| Tokenized POS payments | [Mews tokenization](https://www.mews.com/en/products/tokenization) | ABSENT | [api.php:88](../apps/api/routes/api.php:88) | No POS payment client |
| Tokenized kiosk payments | [Mews tokenization](https://www.mews.com/en/products/tokenization) | ABSENT | [api.php:32](../apps/api/routes/api.php:32) | No kiosk |
| Tokenized terminal tap | [Mews tokenization](https://www.mews.com/en/products/tokenization) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | No terminal |
| Tokenized post-auth charges | [Mews tokenization](https://www.mews.com/en/products/tokenization) | ABSENT | [Payment.php:16](../apps/api/app/Models/Payment.php:16) | No stored payment credential |
| Card-vault disable operation | [Connector credit cards](https://docs.mews.com/connector-api/operations/creditcards.md) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | No credit-card vault |
| Charge tokenized card | [Connector credit cards](https://docs.mews.com/connector-api/operations/creditcards.md) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | No charge-card operation |

## 4. Automated reconciliation, accounting, and AR

| Mews capability | Source | Status | Inn evidence | Mismatch |
|---|---|---:|---|---|
| Auto-match payment to booking | [Mews reconciliation](https://www.mews.com/en/products/automated-reconciliation) | ABSENT | [PaymentService.php:71](../apps/api/app/Services/PaymentService.php:71) | Reconciliation is staff-triggered |
| Auto-match virtual-card payment | [Mews reconciliation](https://www.mews.com/en/products/automated-reconciliation) | ABSENT | [Payment.php:16](../apps/api/app/Models/Payment.php:16) | No virtual-card data |
| Auto-match wire payment | [Mews reconciliation](https://www.mews.com/en/products/automated-reconciliation) | ABSENT | [PaymentService.php:71](../apps/api/app/Services/PaymentService.php:71) | No bank-feed or wire matcher |
| Real-time payment ledger sync | [Mews reconciliation](https://www.mews.com/en/products/automated-reconciliation) | PARTIAL | [FolioService.php:51](../apps/api/app/Services/FolioService.php:51) | Internal folio posting exists; no processor synchronization |
| Daily reconciliation report | [Mews reconciliation](https://www.mews.com/en/products/automated-reconciliation) | ABSENT | [ReportExportResource.php:19](../apps/api/app/Filament/Resources/ReportExports/ReportExportResource.php:19) | No executable daily reconciliation report |
| Daily payout report | [Mews reconciliation](https://www.mews.com/en/products/automated-reconciliation) | ABSENT | [api.php:45](../apps/api/routes/api.php:45) | No payout records |
| Daily balance report | [Mews reconciliation](https://www.mews.com/en/products/automated-reconciliation) | PARTIAL | [FinanceProjectionService.php:133](../apps/api/app/Services/Projections/FinanceProjectionService.php:133) | Internal summary, not Mews payment balance report |
| Chargeback dashboard | [Mews reconciliation](https://www.mews.com/en/products/automated-reconciliation) | ABSENT | [FinanceDashboard.php:89](../apps/api/app/Filament/Pages/FinanceDashboard.php:89) | No chargeback data |
| Export-ready accounting data | [Mews reconciliation](https://www.mews.com/en/products/automated-reconciliation) | PARTIAL | [SafeCsvExporter.php:11](../apps/api/app/Services/SafeCsvExporter.php:11) | CSV utility exists; no complete accounting export pipeline |
| Live ledger | [Mews accounting](https://www.mews.com/en/products/accounting-software) | PARTIAL | [FolioService.php:104](../apps/api/app/Services/FolioService.php:104) | Folio summary exists; no Mews ledger suite |
| Charges, payments, taxes, credits | [Mews accounting](https://www.mews.com/en/products/accounting-software) | PARTIAL | [FolioService.php:186](../apps/api/app/Services/FolioService.php:186) | Charges, payments, tax, refunds exist; no full credit/accounting ledger |
| Append-only folio lines | [Mews accounting](https://www.mews.com/en/products/accounting-software) | FULL | [FolioLine.php:58](../apps/api/app/Models/FolioLine.php:58) | Strong internal ledger behavior, but not Mews accounting categories |
| Folio close/reopen | [Connector bills](https://docs.mews.com/connector-api/operations/bills.md) | FULL | [FolioService.php:129](../apps/api/app/Services/FolioService.php:129) | Internal reservation folio, not Mews bill contract |
| Locked trial balance | [Mews accounting](https://www.mews.com/en/products/accounting-software) | ABSENT | [api.php:92](../apps/api/routes/api.php:92) | No trial-balance model or endpoint |
| Revenue/tax split | [Mews accounting](https://www.mews.com/en/products/accounting-software) | PARTIAL | [FolioService.php:186](../apps/api/app/Services/FolioService.php:186) | Tax fields exist; no accounting-ledger separation |
| Charge routing by guest/company/group | [Mews accounting](https://www.mews.com/en/products/accounting-software) | ABSENT | [FolioController.php:30](../apps/api/app/Http/Controllers/Api/V1/FolioController.php:30) | No payer/routing model |
| Continuous billing routing | [Connector billing automations](https://docs.mews.com/connector-api/operations/billingautomations.md) | ABSENT | [api.php:71](../apps/api/routes/api.php:71) | No billing-automation engine |
| Monthly invoices for long stays | [Mews accounting](https://www.mews.com/en/products/accounting-software) | ABSENT | [api.php:71](../apps/api/routes/api.php:71) | No invoice scheduler |
| Exception flags for billing | [Mews accounting](https://www.mews.com/en/products/accounting-software) | ABSENT | [api.php:71](../apps/api/routes/api.php:71) | No billing exceptions |
| Billing audit trail | [Mews accounting](https://www.mews.com/en/products/accounting-software) | PARTIAL | [Audit.php:1](../apps/api/app/Models/Audit.php:1) | General audit exists; no billing-specific trail |
| Automatic rebate posting to service date | [Mews accounting](https://www.mews.com/en/products/accounting-software) | ABSENT | [FolioService.php:19](../apps/api/app/Services/FolioService.php:19) | No rebate engine |
| Automatic extras-to-bill routing | [Mews accounting](https://www.mews.com/en/products/accounting-software) | PARTIAL | [RetailPostingService.php:22](../apps/api/app/Services/RetailPostingService.php:22) | Reservation-linked retail can post; no category/rate/company routing |
| Virtual-card-to-bill posting | [Mews accounting](https://www.mews.com/en/products/accounting-software) | ABSENT | [Payment.php:16](../apps/api/app/Models/Payment.php:16) | No virtual cards |
| POS-payment real-time invoice sync | [Mews accounting](https://www.mews.com/en/products/accounting-software) | ABSENT | [api.php:88](../apps/api/routes/api.php:88) | Retail endpoint is not Mews POS |
| Invoice creation | [Mews AR](https://www.mews.com/en/products/accounts-receivable) | ABSENT | [api.php:71](../apps/api/routes/api.php:71) | No invoice model |
| Invoice sending | [Mews AR](https://www.mews.com/en/products/accounts-receivable) | ABSENT | [CommunicationDeliveryService.php:78](../apps/api/app/Services/CommunicationDeliveryService.php:78) | Email exists, but no invoice generation/send workflow |
| Invoice reminders | [Mews AR](https://www.mews.com/en/products/accounts-receivable) | ABSENT | [api.php:71](../apps/api/routes/api.php:71) | No AR reminders |
| Wire-transfer collection | [Mews AR](https://www.mews.com/en/products/accounts-receivable) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | Manual bank-transfer records only |
| Full invoice payment | [Mews AR](https://www.mews.com/en/products/accounts-receivable) | ABSENT | [api.php:71](../apps/api/routes/api.php:71) | No invoices |
| Partial invoice payment | [Mews AR](https://www.mews.com/en/products/accounts-receivable) | ABSENT | [api.php:71](../apps/api/routes/api.php:71) | No invoice settlement |
| Split invoice payment | [Mews AR](https://www.mews.com/en/products/accounts-receivable) | ABSENT | [api.php:71](../apps/api/routes/api.php:71) | No invoice settlement |
| Bulk payment across invoices | [Mews AR](https://www.mews.com/en/products/accounts-receivable) | ABSENT | [api.php:71](../apps/api/routes/api.php:71) | No invoice settlement |
| Paid/pending/overdue AR visibility | [Mews AR](https://www.mews.com/en/products/accounts-receivable) | PARTIAL | [FinanceProjectionService.php:145](../apps/api/app/Services/Projections/FinanceProjectionService.php:145) | Deposit due/overdue visibility exists; no invoice AR aging |
| AR funds identification | [Mews AR](https://www.mews.com/en/products/accounts-receivable) | PARTIAL | [PaymentService.php:28](../apps/api/app/Services/PaymentService.php:28) | Provider reference/evidence exists; no AR-origin settlement |
| Company account statements | [Mews accounting](https://www.mews.com/en/products/accounting-software) | ABSENT | [Organization.php:1](../apps/api/app/Models/Organization.php:1) | Organizations exist without invoice statements |
| Reminder notes/pause | [Mews accounting](https://www.mews.com/en/products/accounting-software) | ABSENT | [api.php:71](../apps/api/routes/api.php:71) | No AR reminder state |
| City ledger | [Connector ledger balances](https://docs.mews.com/connector-api/operations/ledgerbalances.md) | ABSENT | [api.php:92](../apps/api/routes/api.php:92) | No city-ledger accounting |
| Trial balance | [Connector ledger balances](https://docs.mews.com/connector-api/operations/ledgerbalances.md) | ABSENT | [api.php:92](../apps/api/routes/api.php:92) | No trial-balance implementation |
| Accounting categories | [Connector accounting categories](https://docs.mews.com/connector-api/operations/accountingcategories.md) | ABSENT | [api.php:71](../apps/api/routes/api.php:71) | No accounting-category catalog |
| Accounting items | [Connector accounting items](https://docs.mews.com/connector-api/operations/accountingitems.md) | PARTIAL | [FolioLine.php:16](../apps/api/app/Models/FolioLine.php:16) | Folio lines are not Mews accounting items |
| Cashiers | [Connector cashiers](https://docs.mews.com/connector-api/operations/cashiers.md) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | No cashier model |
| Cashier transactions | [Connector cashier transactions](https://docs.mews.com/connector-api/operations/cashiertransactions.md) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | No cashier transaction model |
| Outlet bills | [Connector outlet bills](https://docs.mews.com/connector-api/operations/outletbills.md) | ABSENT | [api.php:88](../apps/api/routes/api.php:88) | Retail sale is not an outlet-bill API |
| Payment-to-invoice linking | [Connector payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [PaymentService.php:103](../apps/api/app/Services/PaymentService.php:103) | Payment posts to folio, never an invoice |
| External accounting sync | [Mews accounting](https://www.mews.com/en/products/accounting-software) | ADAPTER-ONLY | [IntegrationConnection.php:7](../apps/api/app/Models/IntegrationConnection.php:7) | Generic accounting connection stores configuration; no provider client |

## 5. Multicurrency and FX

| Mews capability | Source | Status | Inn evidence | Mismatch |
|---|---|---:|---|---|
| Internal exchange-rate snapshots | [Mews multicurrency](https://www.mews.com/en/products/multicurrency) | FULL | [ExchangeRateService.php:14](../apps/api/app/Services/ExchangeRateService.php:14) | Internal FX only |
| Immutable effective-date rates | [Mews multicurrency](https://www.mews.com/en/products/multicurrency) | FULL | [ExchangeRate.php:30](../apps/api/app/Models/ExchangeRate.php:30) | No Mews payment conversion |
| Direct/inverse conversion | [Mews multicurrency](https://www.mews.com/en/products/multicurrency) | FULL | [ExchangeRateService.php:84](../apps/api/app/Services/ExchangeRateService.php:84) | Used for internal reporting |
| Property-specific FX rates | [Mews multicurrency](https://www.mews.com/en/products/multicurrency) | FULL | [ExchangeRateService.php:59](../apps/api/app/Services/ExchangeRateService.php:59) | No payment settlement |
| Raw totals by currency | [Mews BI](https://www.mews.com/en/products/mews-bi) | FULL | [FinancialReportingService.php:113](../apps/api/app/Services/FinancialReportingService.php:113) | Internal reporting |
| Consolidated reporting currency | [Mews BI](https://www.mews.com/en/products/mews-bi) | PARTIAL | [FinancialReportingService.php:270](../apps/api/app/Services/FinancialReportingService.php:270) | Missing-rate handling can make consolidated totals unavailable |
| Guest pays in home currency online | [Mews multicurrency](https://www.mews.com/en/products/multicurrency) | ABSENT | [api.php:58](../apps/api/routes/api.php:58) | No public booking/payment flow |
| Guest pays in home currency in person | [Mews multicurrency](https://www.mews.com/en/products/multicurrency) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | No terminal/payment flow |
| Transparent conversion fee | [Mews multicurrency](https://www.mews.com/en/products/multicurrency) | ABSENT | [Payment.php:16](../apps/api/app/Models/Payment.php:16) | No guest-facing conversion quote |
| Conversion-fee revenue share | [Mews multicurrency](https://www.mews.com/en/products/multicurrency) | ABSENT | [FinancialReportingService.php:19](../apps/api/app/Services/FinancialReportingService.php:19) | No DCC/conversion-fee revenue |
| Booking Engine multicurrency | [Mews multicurrency](https://www.mews.com/en/products/multicurrency) | ABSENT | [api.php:58](../apps/api/routes/api.php:58) | No Booking Engine |
| Kiosk multicurrency | [Mews multicurrency](https://www.mews.com/en/products/multicurrency) | ABSENT | [api.php:32](../apps/api/routes/api.php:32) | No kiosk |
| Terminal multicurrency | [Mews multicurrency](https://www.mews.com/en/products/multicurrency) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | No terminal |
| Broad currency coverage | [Mews multicurrency](https://www.mews.com/en/products/multicurrency) | PARTIAL | [FinanceDashboard.php:65](../apps/api/app/Filament/Pages/FinanceDashboard.php:65) | Inn UI limits options to tenant currency, USD, and ARS |

## 6. Terminals

| Mews capability | Source | Status | Inn evidence | Mismatch |
|---|---|---:|---|---|
| Integrated payment terminal | [Mews terminals](https://www.mews.com/en/products/terminals) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | No device integration |
| Chip/EMV | [Mews terminals](https://www.mews.com/en/products/terminals) | ABSENT | [PaymentService.php:23](../apps/api/app/Services/PaymentService.php:23) | Manual card record only |
| PIN payments | [Mews terminals](https://www.mews.com/en/products/terminals) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | No terminal |
| Magnetic stripe | [Mews terminals](https://www.mews.com/en/products/terminals) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | No terminal |
| Contactless | [Mews terminals](https://www.mews.com/en/products/terminals) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | No terminal |
| P2PE/encrypted terminal transaction | [Mews terminals](https://www.mews.com/en/products/terminals) | ABSENT | [Payment.php:16](../apps/api/app/Models/Payment.php:16) | No encrypted card transaction path |
| Reception terminal | [Mews terminals](https://www.mews.com/en/products/terminals) | ABSENT | [api.php:45](../apps/api/routes/api.php:45) | No hardware workflow |
| Kiosk terminal | [Mews terminals](https://www.mews.com/en/products/terminals) | ABSENT | [api.php:32](../apps/api/routes/api.php:32) | No kiosk |
| POS/F&B terminal | [Mews terminals](https://www.mews.com/en/products/terminals) | ABSENT | [api.php:88](../apps/api/routes/api.php:88) | Retail sales are not POS terminal sales |
| Mobile/cellular terminal | [Mews terminals](https://www.mews.com/en/products/terminals) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | No device client |
| Automatic PMS posting | [Mews terminals](https://www.mews.com/en/products/terminals) | ABSENT | [PaymentService.php:103](../apps/api/app/Services/PaymentService.php:103) | Only manually reconciled payments post |
| Automatic receipt email | [Mews terminals](https://www.mews.com/en/products/terminals) | ABSENT | [CommunicationDeliveryService.php:78](../apps/api/app/Services/CommunicationDeliveryService.php:78) | Email exists, but no terminal receipt event |
| Terminal tipping | [Mews terminals](https://www.mews.com/en/products/terminals) | ABSENT | [StorePaymentRequest.php:9](../apps/api/app/Http/Requests/StorePaymentRequest.php:9) | No tip support |
| Mews Terminal S2/A1/A2/A3/A4 device support | [Mews terminals](https://www.mews.com/en/products/terminals) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | No hardware SDK/API |

## 7. Flexible financing

| Mews capability | Source | Status | Inn evidence | Mismatch |
|---|---|---:|---|---|
| Short-term working-capital funding | [Mews Flexible Financing](https://www.mews.com/en/products/youlend-partnership) | ABSENT | [api.php:45](../apps/api/routes/api.php:45) | No financing product |
| 24–72-hour funding offer | [Mews Flexible Financing](https://www.mews.com/en/products/youlend-partnership) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | No YouLend integration |
| Revenue-based repayment | [Mews Flexible Financing](https://www.mews.com/en/products/youlend-partnership) | ABSENT | [FinancialReportingService.php:19](../apps/api/app/Services/FinancialReportingService.php:19) | Margin/reporting is not financing |
| Automatic percentage-of-sales repayment | [Mews Flexible Financing](https://www.mews.com/en/products/youlend-partnership) | ABSENT | [api.php:92](../apps/api/routes/api.php:92) | No lender or repayment schedule |
| Financing application flow | [Mews Flexible Financing](https://www.mews.com/en/products/youlend-partnership) | ABSENT | [IntegrationConnection.php:7](../apps/api/app/Models/IntegrationConnection.php:7) | Generic integration type does not include financing |

## 8. Revenue management, rates, pricing, and restrictions

| Mews capability | Source | Status | Inn evidence | Mismatch |
|---|---|---:|---|---|
| Rate-plan model | [Connector rates](https://docs.mews.com/connector-api/operations/rates.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | Programs/catalog pricing are not rate plans |
| Public/private rates | [Connector rates](https://docs.mews.com/connector-api/operations/rates.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No public/private rate type |
| Rate groups | [Connector rate groups](https://docs.mews.com/connector-api/operations/rategroups.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No rate-group model |
| Base-rate pricing | [Connector rates](https://docs.mews.com/connector-api/operations/rates.md) | PARTIAL | [ProposalService.php:264](../apps/api/app/Services/ProposalService.php:264) | Proposal lines have manual prices, not rate-engine pricing |
| Dependent-rate pricing | [Connector rates](https://docs.mews.com/connector-api/operations/rates.md) | ABSENT | [ProposalService.php:264](../apps/api/app/Services/ProposalService.php:264) | No inheritance/dependency |
| Occupancy adjustments | [Connector rates](https://docs.mews.com/connector-api/operations/rates.md) | ABSENT | [Reservation.php:38](../apps/api/app/Models/Reservation.php:38) | Adults/children are stored but do not drive rate rules |
| Age-category pricing | [Connector rates](https://docs.mews.com/connector-api/operations/rates.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No age-category rate engine |
| Get rate pricing | [Connector rates](https://docs.mews.com/connector-api/operations/rates.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No rate-pricing endpoint |
| Update rate price | [Connector rates](https://docs.mews.com/connector-api/operations/rates.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No rate-price mutation |
| Capacity-offset pricing | [Connector rates](https://docs.mews.com/connector-api/operations/rates.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No capacity-offset logic |
| Set rates | [Connector rates](https://docs.mews.com/connector-api/operations/rates.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No rate-setting endpoint |
| Delete rates | [Connector rates](https://docs.mews.com/connector-api/operations/rates.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No rate lifecycle |
| Stay restrictions | [Connector restrictions](https://docs.mews.com/connector-api/operations/restrictions.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No restriction engine |
| Start/check-in restrictions | [Connector restrictions](https://docs.mews.com/connector-api/operations/restrictions.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No restriction type |
| End/check-out restrictions | [Connector restrictions](https://docs.mews.com/connector-api/operations/restrictions.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No restriction type |
| Minimum advance booking | [Connector restrictions](https://docs.mews.com/connector-api/operations/restrictions.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No earliness restriction |
| Maximum advance booking | [Connector restrictions](https://docs.mews.com/connector-api/operations/restrictions.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No earliness restriction |
| Minimum length of stay | [Connector restrictions](https://docs.mews.com/connector-api/operations/restrictions.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No LOS rule |
| Maximum length of stay | [Connector restrictions](https://docs.mews.com/connector-api/operations/restrictions.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No LOS rule |
| Minimum/maximum price restrictions | [Connector restrictions](https://docs.mews.com/connector-api/operations/restrictions.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No price restriction |
| Day-of-week restrictions | [Connector restrictions](https://docs.mews.com/connector-api/operations/restrictions.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No weekday restriction |
| Service availability adjustments | [Connector services](https://docs.mews.com/connector-api/operations/services.md) | ABSENT | [api.php:67](../apps/api/routes/api.php:67) | Service occurrences are bookings, not availability adjustments |
| Service overbooking limits | [Connector overbooking limits](https://docs.mews.com/connector-api/operations/serviceoverbookinglimits.md) | ABSENT | [AvailabilityService.php:17](../apps/api/app/Services/AvailabilityService.php:17) | Conflict detection is not configurable overbooking |
| Vouchers | [Connector vouchers](https://docs.mews.com/connector-api/operations/vouchers.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No voucher model |
| Voucher codes | [Connector voucher codes](https://docs.mews.com/connector-api/operations/vouchercodes.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No voucher code validation |
| Dynamic pricing | [Mews dynamic pricing](https://www.mews.com/en/products/dynamic-pricing-automation) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No RMS or automated price updates |
| Daily automated pricing | [Mews dynamic pricing](https://www.mews.com/en/products/dynamic-pricing-automation) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No daily pricing job |
| Intraday pricing | [Mews dynamic pricing](https://www.mews.com/en/products/dynamic-pricing-automation) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No intraday price engine |
| Room-type independent pricing | [Mews dynamic pricing](https://www.mews.com/en/products/dynamic-pricing-automation) | ABSENT | [Resource.php:1](../apps/api/app/Models/Resource.php:1) | Resources exist, but no room-type yield pricing |
| Rate hierarchy protection | [Mews dynamic pricing](https://www.mews.com/en/products/dynamic-pricing-automation) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No rate hierarchy |
| Booking-pace input | [Mews demand forecasting](https://www.mews.com/en/products/demand-forecasting-controls) | ABSENT | [FinanceProjectionService.php:203](../apps/api/app/Services/Projections/FinanceProjectionService.php:203) | Revenue series is historical/posted, not pickup pace |
| Competitor-rate input | [Mews demand forecasting](https://www.mews.com/en/products/demand-forecasting-controls) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | No comp-set feed |
| Market-demand input | [Mews demand forecasting](https://www.mews.com/en/products/demand-forecasting-controls) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | No market data |
| Historical pace analysis | [Mews demand forecasting](https://www.mews.com/en/products/demand-forecasting-controls) | ABSENT | [FinanceProjectionService.php:203](../apps/api/app/Services/Projections/FinanceProjectionService.php:203) | No historical pickup/pace model |
| Cancellation analysis | [Mews demand forecasting](https://www.mews.com/en/products/demand-forecasting-controls) | ABSENT | [Reservation.php:38](../apps/api/app/Models/Reservation.php:38) | Cancellation state exists, not forecasting |
| Event-demand adjustment | [Mews demand forecasting](https://www.mews.com/en/products/demand-forecasting-controls) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No event-demand input |
| Seasonality adjustment | [Mews demand forecasting](https://www.mews.com/en/products/demand-forecasting-controls) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No seasonality model |
| Adjacent-day logic | [Mews demand forecasting](https://www.mews.com/en/products/demand-forecasting-controls) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No stay-pattern optimizer |
| 24-month forecast | [Mews demand forecasting](https://www.mews.com/en/products/demand-forecasting-controls) | ABSENT | [FinanceProjectionService.php:203](../apps/api/app/Services/Projections/FinanceProjectionService.php:203) | Inn revenue series is seven months |
| Budget alignment | [Mews demand forecasting](https://www.mews.com/en/products/demand-forecasting-controls) | ABSENT | [FinancialReportingService.php:19](../apps/api/app/Services/FinancialReportingService.php:19) | No budget model |
| Real-time rate publication | [Mews demand forecasting](https://www.mews.com/en/products/demand-forecasting-controls) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No rate distribution |
| Mews RMS / Atomize | [Mews RMS](https://www.mews.com/en/products/revenue-management-system) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | No RMS implementation or adapter |
| Portfolio pricing strategy | [Mews RMS](https://www.mews.com/en/products/revenue-management-system) | ABSENT | [api.php:51](../apps/api/routes/api.php:51) | Property records exist, but no chain pricing layer |

## 9. BI and data reporting

| Mews capability | Source | Status | Inn evidence | Mismatch |
|---|---|---:|---|---|
| Finance dashboard | [Mews BI](https://www.mews.com/en/products/mews-bi) | PARTIAL | [FinanceDashboard.php:16](../apps/api/app/Filament/Pages/FinanceDashboard.php:16) | Inn has four finance metrics, not Mews BI |
| Booked revenue metric | [Mews BI](https://www.mews.com/en/products/mews-bi) | FULL | [FinanceOverview.php:51](../apps/api/app/Filament/Widgets/FinanceOverview.php:51) | Internal booked-revenue metric |
| Cash-collected metric | [Mews BI](https://www.mews.com/en/products/mews-bi) | FULL | [FinanceOverview.php:58](../apps/api/app/Filament/Widgets/FinanceOverview.php:58) | Uses locally reconciled payments |
| Receivables metric | [Mews BI](https://www.mews.com/en/products/mews-bi) | PARTIAL | [FinanceOverview.php:64](../apps/api/app/Filament/Widgets/FinanceOverview.php:64) | Deposit/reservation balance, not invoice AR |
| Cost metric | [Mews BI](https://www.mews.com/en/products/mews-bi) | FULL | [FinancialReportingService.php:35](../apps/api/app/Services/FinancialReportingService.php:35) | Internal cost records |
| Commission metric | [Mews BI](https://www.mews.com/en/products/mews-bi) | FULL | [FinancialReportingService.php:44](../apps/api/app/Services/FinancialReportingService.php:44) | Accruals, not external channel commissions |
| Margin metric | [Mews BI](https://www.mews.com/en/products/mews-bi) | PARTIAL | [FinancialReportingService.php:49](../apps/api/app/Services/FinancialReportingService.php:49) | Simple booked-cost-commission formula |
| Occupancy | [Mews BI](https://www.mews.com/en/products/mews-bi) | FULL | [DashboardProjectionService.php:143](../apps/api/app/Services/Projections/DashboardProjectionService.php:143) | Internal stay-place occupancy |
| ADR | [Mews BI](https://www.mews.com/en/products/mews-bi) | ABSENT | [FinanceProjectionService.php:133](../apps/api/app/Services/Projections/FinanceProjectionService.php:133) | No ADR calculation |
| RevPAR | [Mews BI](https://www.mews.com/en/products/mews-bi) | ABSENT | [FinanceProjectionService.php:133](../apps/api/app/Services/Projections/FinanceProjectionService.php:133) | No RevPAR calculation |
| Pickup reporting | [Mews BI](https://www.mews.com/en/products/mews-bi) | ABSENT | [FinanceProjectionService.php:203](../apps/api/app/Services/Projections/FinanceProjectionService.php:203) | No pickup/pace dimensions |
| Segment reporting | [Mews BI](https://www.mews.com/en/products/mews-bi) | PARTIAL | [FinanceProjectionService.php:231](../apps/api/app/Services/Projections/FinanceProjectionService.php:231) | Programs/channels only; no Mews segment model |
| Product-revenue reporting | [Mews BI](https://www.mews.com/en/products/mews-bi) | PARTIAL | [ExtendedOperationsController.php:33](../apps/api/app/Http/Controllers/Api/V1/ExtendedOperationsController.php:33) | Catalog exists; no complete product BI |
| Space/property reporting | [Mews BI](https://www.mews.com/en/products/mews-bi) | PARTIAL | [FinanceProjectionService.php:29](../apps/api/app/Services/Projections/FinanceProjectionService.php:29) | Property scoping exists; no portfolio BI |
| Cross-property reporting | [Mews BI](https://www.mews.com/en/products/mews-bi) | PARTIAL | [FinancialReportingService.php:19](../apps/api/app/Services/FinancialReportingService.php:19) | Tenant aggregation exists, not chain BI |
| Accounting-logic-aligned dashboards | [Mews BI](https://www.mews.com/en/products/mews-bi) | PARTIAL | [FinanceProjectionService.php:173](../apps/api/app/Services/Projections/FinanceProjectionService.php:173) | Internal formula, no accounting engine |
| Prebuilt dashboards | [Mews BI](https://www.mews.com/en/products/mews-bi) | PARTIAL | [FinanceOverview.php:32](../apps/api/app/Filament/Widgets/FinanceOverview.php:32) | Four fixed cards, not broad Mews library |
| Drag-and-drop custom dashboards | [Mews BI](https://www.mews.com/en/products/mews-bi) | ABSENT | [FinanceDashboard.php:16](../apps/api/app/Filament/Pages/FinanceDashboard.php:16) | No dashboard builder |
| AI-generated summaries | [Mews BI](https://www.mews.com/en/products/mews-bi) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | No AI/report-summary service |
| Google Ads data connection | [Mews BI](https://www.mews.com/en/products/mews-bi) | ABSENT | [IntegrationConnection.php:7](../apps/api/app/Models/IntegrationConnection.php:7) | Generic integration record only |
| Seven-month revenue series | [Mews BI](https://www.mews.com/en/products/mews-bi) | FULL | [FinanceProjectionService.php:203](../apps/api/app/Services/Projections/FinanceProjectionService.php:203) | Inn has a fixed seven-month chart |
| PMS occupancy/availability report | [Hotel data reporting](https://www.mews.com/en/products/hotel-data-reporting-software) | PARTIAL | [DashboardProjectionService.php:176](../apps/api/app/Services/Projections/DashboardProjectionService.php:176) | Occupancy trend exists; no full report catalog |
| POS sales report | [Hotel data reporting](https://www.mews.com/en/products/hotel-data-reporting-software) | PARTIAL | [ExtendedOperationsController.php:102](../apps/api/app/Http/Controllers/Api/V1/ExtendedOperationsController.php:102) | Retail sales exist, not Mews POS analytics |
| POS order report | [Hotel data reporting](https://www.mews.com/en/products/hotel-data-reporting-software) | ABSENT | [api.php:88](../apps/api/routes/api.php:88) | No POS order model |
| POS revenue-center report | [Hotel data reporting](https://www.mews.com/en/products/hotel-data-reporting-software) | ABSENT | [api.php:88](../apps/api/routes/api.php:88) | No revenue-center model |
| Menu/product-mix report | [Hotel data reporting](https://www.mews.com/en/products/hotel-data-reporting-software) | ABSENT | [CatalogItem.php:1](../apps/api/app/Models/CatalogItem.php:1) | Catalog exists, no product-mix analytics |
| Inventory report | [Hotel data reporting](https://www.mews.com/en/products/hotel-data-reporting-software) | PARTIAL | [ExtendedOperationsController.php:72](../apps/api/app/Http/Controllers/Api/V1/ExtendedOperationsController.php:72) | Stock movements exist, no waste/usage reporting |
| Labor/employee report | [Hotel data reporting](https://www.mews.com/en/products/hotel-data-reporting-software) | ABSENT | [api.php:93](../apps/api/routes/api.php:93) | Staff/cost references exist, no labor analytics |
| Guest-insight report | [Hotel data reporting](https://www.mews.com/en/products/hotel-data-reporting-software) | ABSENT | [Guest.php:1](../apps/api/app/Models/Guest.php:1) | Guest data exists, no purchasing/frequency/loyalty report |
| Discount/refund/void report | [Hotel data reporting](https://www.mews.com/en/products/hotel-data-reporting-software) | PARTIAL | [FolioService.php:76](../apps/api/app/Services/FolioService.php:76) | Reversals exist, no leakage report |
| Filter by property | [Hotel data reporting](https://www.mews.com/en/products/hotel-data-reporting-software) | FULL | [FinancialReportingService.php:19](../apps/api/app/Services/FinancialReportingService.php:19) | Property filter exists |
| Filter by outlet | [Hotel data reporting](https://www.mews.com/en/products/hotel-data-reporting-software) | ABSENT | [api.php:88](../apps/api/routes/api.php:88) | No outlet model |
| Filter by revenue stream | [Hotel data reporting](https://www.mews.com/en/products/hotel-data-reporting-software) | PARTIAL | [FinanceProjectionService.php:231](../apps/api/app/Services/Projections/FinanceProjectionService.php:231) | Programs/channels, not full revenue-stream taxonomy |
| Filter by time period | [Hotel data reporting](https://www.mews.com/en/products/hotel-data-reporting-software) | FULL | [FinanceDashboard.php:28](../apps/api/app/Filament/Pages/FinanceDashboard.php:28) | Date range exists |
| Filter by team | [Hotel data reporting](https://www.mews.com/en/products/hotel-data-reporting-software) | ABSENT | [FinanceDashboard.php:16](../apps/api/app/Filament/Pages/FinanceDashboard.php:16) | No team-report filter |
| CSV export | [Mews BI](https://www.mews.com/en/products/mews-bi) | PARTIAL | [SafeCsvExporter.php:11](../apps/api/app/Services/SafeCsvExporter.php:11) | Utility returns CSV; no complete report-export workflow |
| PDF export | [Mews accounting](https://www.mews.com/en/products/accounting-software) | PARTIAL | [DocumentService.php:37](../apps/api/app/Services/DocumentService.php:37) | Stores supplied PDF bytes; does not generate Mews reports |
| Excel export | [Mews BI](https://www.mews.com/en/products/mews-bi) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | No spreadsheet export library |
| PowerPoint export | [Mews BI](https://www.mews.com/en/products/mews-bi) | ABSENT | [composer.json:8](../apps/api/composer.json:8) | No presentation export |
| Scheduled report delivery | [Mews BI](https://www.mews.com/en/products/mews-bi) | ABSENT | [ReportExportResource.php:33](../apps/api/app/Filament/Resources/ReportExports/ReportExportResource.php:33) | Report export resource is read-only; no scheduler |
| Report-export job tracking | [Connector exports](https://docs.mews.com/connector-api/operations/exports.md) | ADAPTER-ONLY | [ReportExport.php:7](../apps/api/app/Models/ReportExport.php:7) | Model/resource exists, but no creation/execution path |
| Data freshness/live updates | [Hotel data reporting](https://www.mews.com/en/products/hotel-data-reporting-software) | PARTIAL | [FinanceProjectionService.php:41](../apps/api/app/Services/Projections/FinanceProjectionService.php:41) | Short cache exists; no Mews unified live data pipeline |

## 10. Mews finance/revenue API parity

These are distinct public Connector API capabilities. Inn has no Mews-compatible Connector implementation.

| Mews API capability | Source | Status | Inn evidence | Mismatch |
|---|---|---:|---|---|
| Get accounting categories | [Accounting categories](https://docs.mews.com/connector-api/operations/accountingcategories.md) | ABSENT | [api.php:71](../apps/api/routes/api.php:71) | No endpoint |
| Get accounting items | [Accounting items](https://docs.mews.com/connector-api/operations/accountingitems.md) | ABSENT | [api.php:71](../apps/api/routes/api.php:71) | Folio lines are not Connector resources |
| Update accounting items | [Accounting items](https://docs.mews.com/connector-api/operations/accountingitems.md) | ABSENT | [FolioLine.php:72](../apps/api/app/Models/FolioLine.php:72) | Inn folio lines are immutable |
| Get billing automations | [Billing automations](https://docs.mews.com/connector-api/operations/billingautomations.md) | ABSENT | [api.php:71](../apps/api/routes/api.php:71) | Reservation milestone automation is different |
| Add billing automations | [Billing automations](https://docs.mews.com/connector-api/operations/billingautomations.md) | ABSENT | [api.php:71](../apps/api/routes/api.php:71) | No Mews billing automation |
| Update billing automations | [Billing automations](https://docs.mews.com/connector-api/operations/billingautomations.md) | ABSENT | [api.php:71](../apps/api/routes/api.php:71) | No Mews billing automation |
| Update billing assignments | [Billing automations](https://docs.mews.com/connector-api/operations/billingautomations.md) | ABSENT | [api.php:71](../apps/api/routes/api.php:71) | No assignment model |
| Delete billing automations | [Billing automations](https://docs.mews.com/connector-api/operations/billingautomations.md) | ABSENT | [api.php:71](../apps/api/routes/api.php:71) | No deletion operation |
| Get bills | [Bills](https://docs.mews.com/connector-api/operations/bills.md) | PARTIAL | [FolioController.php:19](../apps/api/app/Http/Controllers/Api/V1/FolioController.php:19) | Folios are internal reservation bills, not Mews bills |
| Get bill PDF | [Bills](https://docs.mews.com/connector-api/operations/bills.md) | PARTIAL | [DocumentService.php:37](../apps/api/app/Services/DocumentService.php:37) | Generic PDF storage, no bill-PDF operation |
| Add bill | [Bills](https://docs.mews.com/connector-api/operations/bills.md) | PARTIAL | [FolioService.php:19](../apps/api/app/Services/FolioService.php:19) | Adds folio lines, not Mews bills |
| Update bills | [Bills](https://docs.mews.com/connector-api/operations/bills.md) | ABSENT | [FolioLine.php:72](../apps/api/app/Models/FolioLine.php:72) | Append-only lines |
| Delete bills | [Bills](https://docs.mews.com/connector-api/operations/bills.md) | ABSENT | [FolioLine.php:73](../apps/api/app/Models/FolioLine.php:73) | No bill deletion |
| Close bill | [Bills](https://docs.mews.com/connector-api/operations/bills.md) | PARTIAL | [FolioService.php:129](../apps/api/app/Services/FolioService.php:129) | Internal folio close only |
| Get cashiers | [Cashiers](https://docs.mews.com/connector-api/operations/cashiers.md) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | No cashier |
| Get cashier transactions | [Cashier transactions](https://docs.mews.com/connector-api/operations/cashiertransactions.md) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | No cashier transaction |
| Get credit cards | [Credit cards](https://docs.mews.com/connector-api/operations/creditcards.md) | ABSENT | [Payment.php:16](../apps/api/app/Models/Payment.php:16) | No card vault |
| Add tokenized credit card | [Credit cards](https://docs.mews.com/connector-api/operations/creditcards.md) | ABSENT | [Payment.php:16](../apps/api/app/Models/Payment.php:16) | No tokenized card |
| Disable gateway credit card | [Credit cards](https://docs.mews.com/connector-api/operations/creditcards.md) | ABSENT | [Payment.php:16](../apps/api/app/Models/Payment.php:16) | No gateway card |
| Charge credit card | [Credit cards](https://docs.mews.com/connector-api/operations/creditcards.md) | ABSENT | [PaymentService.php:23](../apps/api/app/Services/PaymentService.php:23) | Manual record only |
| Get exchange rates | [Exchange rates](https://docs.mews.com/connector-api/operations/exchangerates.md) | PARTIAL | [ExchangeRateService.php:50](../apps/api/app/Services/ExchangeRateService.php:50) | Internal FX snapshots, not Mews exchange-rate API |
| Get exports | [Exports](https://docs.mews.com/connector-api/operations/exports.md) | ADAPTER-ONLY | [ReportExport.php:7](../apps/api/app/Models/ReportExport.php:7) | No Mews export files or JSONL download |
| Add export | [Exports](https://docs.mews.com/connector-api/operations/exports.md) | ADAPTER-ONLY | [ReportExportResource.php:33](../apps/api/app/Filament/Resources/ReportExports/ReportExportResource.php:33) | Resource is read-only |
| Get ledger balances | [Ledger balances](https://docs.mews.com/connector-api/operations/ledgerbalances.md) | PARTIAL | [FinanceProjectionService.php:133](../apps/api/app/Services/Projections/FinanceProjectionService.php:133) | Internal summary, not Mews ledger balances |
| Add outlet bills | [Outlet bills](https://docs.mews.com/connector-api/operations/outletbills.md) | ABSENT | [api.php:88](../apps/api/routes/api.php:88) | No outlets |
| Get payments | [Payments](https://docs.mews.com/connector-api/operations/payments.md) | PARTIAL | [PaymentController.php:22](../apps/api/app/Http/Controllers/Api/V1/PaymentController.php:22) | Internal payments only |
| Add external payment | [Payments](https://docs.mews.com/connector-api/operations/payments.md) | PARTIAL | [PaymentService.php:23](../apps/api/app/Services/PaymentService.php:23) | Manual provider/reference fields, no Mews account/bill semantics |
| Add credit-card payment | [Payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [PaymentService.php:23](../apps/api/app/Services/PaymentService.php:23) | No card charge |
| Add alternative payment | [Payments](https://docs.mews.com/connector-api/operations/payments.md) | ABSENT | [StorePaymentRequest.php:13](../apps/api/app/Http/Requests/StorePaymentRequest.php:13) | No iDEAL/Apple Pay/Google Pay/SEPA flow |
| Refund payment | [Payments](https://docs.mews.com/connector-api/operations/payments.md) | PARTIAL | [PaymentService.php:124](../apps/api/app/Services/PaymentService.php:124) | Internal folio reversal, no provider refund |
| Get payment requests | [Payment requests](https://docs.mews.com/connector-api/operations/paymentrequests.md) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | No request model |
| Add payment requests | [Payment requests](https://docs.mews.com/connector-api/operations/paymentrequests.md) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | No request route |
| Cancel payment requests | [Payment requests](https://docs.mews.com/connector-api/operations/paymentrequests.md) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | No request lifecycle |
| Add payment-method request | [Payment method requests](https://docs.mews.com/connector-api/operations/paymentmethodrequests.md) | ABSENT | [api.php:32](../apps/api/routes/api.php:32) | Payment evidence upload is not a payment-method request |
| Add billing-automation payment plan | [Payment plans](https://docs.mews.com/connector-api/operations/paymentplans.md) | PARTIAL | [ReservationConfirmationProvisioner.php:49](../apps/api/app/Services/ReservationConfirmationProvisioner.php:49) | Deposit schedule only |
| Get preauthorizations | [Preauthorizations](https://docs.mews.com/connector-api/operations/preauthorizations.md) | ABSENT | [api.php:81](../apps/api/routes/api.php:81) | No preauth |
| Get rates | [Rates](https://docs.mews.com/connector-api/operations/rates.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No rate endpoint |
| Get rate pricing | [Rates](https://docs.mews.com/connector-api/operations/rates.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | Proposal prices are not rate pricing |
| Add rates | [Rates](https://docs.mews.com/connector-api/operations/rates.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No rate creation |
| Update rate price | [Rates](https://docs.mews.com/connector-api/operations/rates.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No rate mutation |
| Update capacity-offset pricing | [Rates](https://docs.mews.com/connector-api/operations/rates.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No capacity offsets |
| Set rates | [Rates](https://docs.mews.com/connector-api/operations/rates.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No rate setting |
| Delete rates | [Rates](https://docs.mews.com/connector-api/operations/rates.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No rate deletion |
| Get rate groups | [Rate groups](https://docs.mews.com/connector-api/operations/rategroups.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No rate groups |
| Get restrictions | [Restrictions](https://docs.mews.com/connector-api/operations/restrictions.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No restrictions |
| Set restrictions | [Restrictions](https://docs.mews.com/connector-api/operations/restrictions.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No restrictions |
| Clear restrictions | [Restrictions](https://docs.mews.com/connector-api/operations/restrictions.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No restrictions |
| Get overbooking limits | [Overbooking limits](https://docs.mews.com/connector-api/operations/serviceoverbookinglimits.md) | ABSENT | [AvailabilityService.php:17](../apps/api/app/Services/AvailabilityService.php:17) | No configurable overbooking |
| Set overbooking limits | [Overbooking limits](https://docs.mews.com/connector-api/operations/serviceoverbookinglimits.md) | ABSENT | [AvailabilityService.php:17](../apps/api/app/Services/AvailabilityService.php:17) | No configurable overbooking |
| Clear overbooking limits | [Overbooking limits](https://docs.mews.com/connector-api/operations/serviceoverbookinglimits.md) | ABSENT | [AvailabilityService.php:17](../apps/api/app/Services/AvailabilityService.php:17) | No configurable overbooking |
| Get vouchers | [Vouchers](https://docs.mews.com/connector-api/operations/vouchers.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No voucher model |
| Add vouchers | [Vouchers](https://docs.mews.com/connector-api/operations/vouchers.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No voucher model |
| Update vouchers | [Vouchers](https://docs.mews.com/connector-api/operations/vouchers.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No voucher model |
| Delete vouchers | [Vouchers](https://docs.mews.com/connector-api/operations/vouchers.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No voucher model |
| Get voucher codes | [Voucher codes](https://docs.mews.com/connector-api/operations/vouchercodes.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No voucher-code model |
| Add voucher codes | [Voucher codes](https://docs.mews.com/connector-api/operations/vouchercodes.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No voucher-code model |
| Delete voucher codes | [Voucher codes](https://docs.mews.com/connector-api/operations/vouchercodes.md) | ABSENT | [api.php:52](../apps/api/routes/api.php:52) | No voucher-code model |

## Marketing claims kept separate

These are public Mews claims, not independently verified technical contracts:

- **15,000+ properties**: [Mews Payments](https://www.mews.com/en/products/payments)
- **1,000+ integrations**: [Mews Marketplace](https://www.mews.com/en/products/marketplace)
- **40+ currencies** on the Payments page and **112+ currencies** on the Multicurrency page: [Payments](https://www.mews.com/en/products/payments), [Multicurrency](https://www.mews.com/en/products/multicurrency)
- **150M+ daily RMS calculations** and **20–30 hours saved per revenue manager**: [Mews RMS](https://www.mews.com/en/products/revenue-management-system)
- **24–72-hour financing offers**, revenue-based repayment, and up to **$1M** financing: [Mews Flexible Financing](https://www.mews.com/en/products/youlend-partnership)
- AR reminders are described as **“coming soon”** in the AR FAQ despite the product page presenting the invoice-to-cash workflow: [Mews AR](https://www.mews.com/en/products/accounts-receivable)

Bottom line: Inn has real internal folios, manual payments, deposits, FX snapshots, costs, commissions, finance projections, occupancy, revenue trends, CSV utilities, and role-scoped finance screens. It does not have Mews parity for payment processing, tokenization, terminals, automated reconciliation, invoices/AR, trial balances, accounting categories, rate engines, RMS, dynamic pricing, forecasting, ADR, RevPAR, AI BI, scheduled exports, or Mews finance API compatibility.

## Current catalog cross-check additions

The current Mews PMS and payment pages restate several capabilities already scored above and make their product behavior explicit:

| Current Mews finance/reporting capability | Source | Inn status | Mismatch |
|---|---|---|---|
| Automatic invoice issuance when billing closes | [Introducing Mews OS](https://www.mews.com/en/introducing-mews-os) | ABSENT | Inn has folios/documents but no automatic B2B invoice-to-cash engine. |
| Automated invoice-to-cash workflow | [Introducing Mews OS](https://www.mews.com/en/introducing-mews-os) | ABSENT | No invoice issuance, collection, matching, and AR workflow exists. |
| Native payment embedding across PMS and POS | [Mews Payments](https://www.mews.com/en/products/payments) | ABSENT | Inn records manual payments and has no processor or POS payment execution. |
| Recurring card and SEPA payments | [Mews Payments](https://www.mews.com/en/products/payments) | ABSENT | No recurring mandate, tokenized-card reuse, or SEPA execution exists. |
| Mews BI dashboards refreshed every two hours | [Mews PMS](https://www.mews.com/en/property-management-system) | ABSENT | Inn has fixed internal dashboards and no Mews BI dashboard library or refresh pipeline. |
| Automated PDF, Excel, PowerPoint, or CSV delivery to inbox | [Mews PMS](https://www.mews.com/en/property-management-system) | PARTIAL | CSV/PDF utilities exist, but no Excel/PPT generation or scheduled inbox delivery exists. |

## GitHub/OpenAPI finance and payments addendum

The first-party [Mews Payments Checkout documentation](https://github.com/MewsSystems/open-api-docs/blob/main/mews-payments-checkout/README.md) and Connector use-case pages add payment/accounting behavior that is not represented by the earlier finance rows. The detailed source comparison is in the [GitHub/OpenAPI re-audit](mews-vs-inn-github-openapi-audit.md).

| Mews finance/payment feature | Inn status | Exact mismatch |
|---|---|---|
| Embedded Payments Checkout JavaScript/iframe | ABSENT | No `Mews.PaymentCheckout` loader, responsive iframe, CSP/PCI Proxy setup, or demo/production checkout environment. |
| Checkout capture of a pre-created Connector payment request | ABSENT | No hosted method selection, PCI card capture, 3DS, and Mews-posting flow. |
| Direct checkout capture from enterprise, amount, and currency | ABSENT | No flow that creates the guest account, payment method, payment, and guest link as one checkout contract. |
| Apple Pay and Google Pay | ABSENT | No express-wallet integration or domain activation. |
| iDEAL and SEPA Direct Debit | ABSENT | No redirect or mandate/collection integration. |
| Future payment-method collection | ABSENT | No card/SEPA consent and later Operations/automation/Connector charge path. |
| Checkout lifecycle callbacks | ABSENT | No `payment-charged`, `payment-submitted`, `payment-method-collected`, or failure-event consumer. |
| Checkout configuration/theme/multicurrency/prefill | ABSENT | No SDK configuration for enabled methods, language, styles, tracking, currency, or billing prefill. |
| Customer preauthorizations by customer ID | ABSENT | No preauthorization/card-reference model or `getAllByCustomers` operation. |
| Allowance as bill liability | ABSENT | No allowance product/order-item discriminator or liability balance. |
| Category-matched allowance discount | ABSENT | No automatic discount based on permitted accounting category. |
| Partial/capped allowance consumption | ABSENT | No remaining-balance or capped offset semantics. |
| Allowance breakage, contra-breakage, loss, and contra-loss | ABSENT | No expiry/checkout accounting events for these item types. |
| Allowance posting and reconciliation via `LinkedReservationId` and order-item queries | PARTIAL | Reservation-linked retail posting exists, but not Mews allowance posting, retrieval, and zero-reconciliation behavior. |
| Mews VAT-code workflow | PARTIAL | Tax arithmetic exists, but no TaxEnvironment/Taxation/TaxRate catalog lookup and code propagation. |
| Versioned cancellation-policy CRUD with fee rules | ABSENT | No policy catalog, applicability, absolute/relative fee rules, versioned retrieval, or dependency-aware lifecycle. |
