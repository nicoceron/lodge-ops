# Fiscal issuance decision and approved-input record

Status: **unapproved template — regulated issuance is disabled**
Owner: client legal/accounting owner (name required)
Technical reviewer: Inn release coordinator
Approval date/version: pending

This record is the mandatory input gate for conditional Agent 04B. It is not tax advice, a tax configuration, an authorization to issue documents, or evidence that an Inn confirmation, folio, receipt, refund receipt, or credit document is a regulated fiscal invoice.

## 1. Issuing entity and scope

| Required decision | Approved value | Evidence / authority URL or attachment | Approver and date |
| --- | --- | --- | --- |
| Legal entity name and legal form | **Pending** | | |
| Tax domicile and registered address | **Pending** | | |
| Supported country/jurisdiction and sub-jurisdiction | **Pending** | | |
| Tax registration identifiers and verified format | **Pending** | | |
| Properties and commercial channels covered | **Pending** | | |
| Production fiscal owner and incident contact | **Pending** | | |

## 2. Document classes and customer inputs

| Required decision | Approved value | Evidence / authority URL or attachment | Approver and date |
| --- | --- | --- | --- |
| Invoice/document classes allowed | **Pending** | | |
| Customer classes and ID requirements | **Pending** | | |
| Required seller/buyer fields | **Pending** | | |
| Required line descriptions, units, exemptions and references | **Pending** | | |
| Issuance time and property-local cutoff rules | **Pending** | | |
| Original-document linkage requirements | **Pending** | | |

## 3. Taxes, totals and currency

| Required decision | Approved value | Evidence / authority URL or attachment | Approver and date |
| --- | --- | --- | --- |
| Tax IDs, rates, exemptions and effective dates | **Pending** | | |
| Tax-inclusive/exclusive treatment | **Pending** | | |
| Taxable promotion allocation | **Pending** | | |
| Per-line versus total rounding and exact mode | **Pending** | | |
| Supported invoice currencies | **Pending** | | |
| Exchange-rate source, precision and issue-time rule | **Pending** | | |

No value may be inferred from locale, property time zone, currency, current UI labels, or an example rate. Agent 04 stores these as versioned inputs but does not decide them.

## 4. Numbering, authorization and corrections

| Required decision | Approved value | Evidence / authority URL or attachment | Approver and date |
| --- | --- | --- | --- |
| Numbering authority and authorization identifier | **Pending** | | |
| Point of sale / establishment / series rules | **Pending** | | |
| Sequential numbering and concurrency rule | **Pending** | | |
| Cancellation eligibility and deadline | **Pending** | | |
| Credit/debit note classes and maximum correction rules | **Pending** | | |
| Retention, query and reconciliation requirements | **Pending** | | |

## 5. Authority/provider and environment

| Required decision | Approved value | Evidence / authority URL or attachment | Approver and date |
| --- | --- | --- | --- |
| Tax authority service/API and exact version | **Pending** | | |
| Named provider/API, if any | **Pending** | | |
| Homologation/test environment and account owner | **Pending** | | |
| Production account/delegation owner | **Pending** | | |
| Certificate/key custody and secret reference system | **Pending** | | |
| Availability, retry, timeout and reconciliation contract | **Pending** | | |

Secrets, certificates and private keys must never be pasted into this record, Git, logs, screenshots, fixtures, queues, or UAT evidence. Record only the approved secret-manager reference pattern and accountable owner.

## 6. Explicit launch decision

Select exactly one and sign it:

- [ ] **Defer regulated fiscal issuance.** Inn continues to produce explicitly non-fiscal operational confirmations, folios, receipts, refund receipts and credit documents. Agent 04B does not run.
- [ ] **Require regulated fiscal issuance.** Every field above is approved and evidenced. Create a separately reviewed `codex/p3-fiscal-<jurisdiction>` branch under Agent 04B; read the current official tax-authority manuals on implementation day; prove homologation issue/query/correction/reconciliation; keep production activation separately gated.

Approver name/title: **Pending**
Signature or approval-record reference: **Pending**
Date: **Pending**

## Current Agent 04 determination

At the 2026-08-20 implementation baseline, no approved legal entity, jurisdiction, invoice class, tax registration/rates, numbering authorization, point of sale, correction rules, exchange-rate rule, fiscal authority/provider contract, or signed deferment exists in the repository. Therefore the Agent 04B decision is **pending**, regulated issuance is **blocked**, and Inn's current operational documents remain non-fiscal. Only a completed approval above may authorize Agent 04B; only a separately recorded written client deferment may classify it as deferred for final certification.
