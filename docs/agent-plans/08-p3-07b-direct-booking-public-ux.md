# Agent 08 — P3-07B direct-booking public UX

## Copy/paste assignment

> Build the complete public booking UX against Agent 06’s frozen contract. Use the checked-in mock fixtures while Agent 07 implements the backend, then rebase on Agent 07 and run the real integrated journey before completion. Read this file, the coordinator README, frozen OpenAPI/state machine/threat model, existing guest/payment pages, brand styles, WCAG 2.2 and Playwright docs. Prefer same-origin Laravel server-rendered/Livewire pages and link the Next marketing site to them. Cover every pending/failure/recovery state, English/Spanish, phone and keyboard use. Do not calculate price, decide availability, or confirm payment in the browser.

## Branch and ownership

- Branch: `codex/p3-07b-direct-booking-ux` after Agent 06; final gate only after Agent 07 is merged/rebased.
- Own **browser page routes in `apps/api/routes/web.php`**, views/components/styles/locales, client-side progressive enhancement, safe analytics, accessibility, Playwright tests, and the marketing-site booking CTA. Agent 07 owns API routes.
- Do not change financial/inventory services or create a Next BFF/CORS boundary without an approved ADR.
- Reuse established guest link, hosted checkout and document-download patterns; do not copy provider SDK/card fields into Inn.

## Required screens and behavior

- Property landing/bookability; dates and guest/group/program input; accessible validation summary.
- Render only published localized property/category/program copy and media with required alt text; unpublished/private assets never appear in HTML, JSON, metadata or image URLs.
- Availability results with public category/program description and “request another date” empty state; never expose exact room IDs/counts.
- Quote breakdown: nights/guests/services, included vs optional, promotion, fees/taxes, total/currency, deposit due, cancellation/no-show and expiry countdown.
- Guest/companion details, privacy/terms/cancellation consent, separate optional marketing consent, bot check and attribution.
- Hold/payment review with visible expiration and idempotent submit; duplicate clicks are disabled but correctness remains server-side.
- Redirect to Mercado Pago hosted checkout. Never render/store PAN/CVV/expiry.
- When the property enables manual bank transfer, show versioned safe instructions → evidence upload/pending/Finance review/correction states; never expose bank instructions for another property/currency or imply that upload equals payment.
- Return/status screens for pending, processing, failed, expired, retryable, paid-needs-review, confirmed, canceled and refunded. Poll Inn status with bounded backoff; return params are display-only.
- Resume from hashed opaque token, recovery after refresh/back, another tab, lost network and checkout expiration. Do not reveal whether an email exists.
- Confirmation includes booking reference, policies, next steps, receipt/document access and guest portal entry only after server confirmation.

## UX quality requirements

- English and Spanish copy for labels, errors, policies and email-safe fallback. Use server-provided monetary/date formatting semantics; no float math.
- Mobile-first at 390×844, tablet and desktop. No hidden primary action, overflow, inaccessible modal or clipped policy.
- WCAG 2.2 AA target: semantic landmarks/headings, labels/instructions, keyboard order, visible focus, skip link, focus restoration, live status, error association/summary, adequate target sizes/contrast, reduced motion and timeout warning/extension behavior.
- Progressive enhancement: core search/review/status works without fragile client state; session token stays in secure URL/cookie boundary defined by Agent 06.
- Consent-gated analytics with allowlisted events and no guest name/email/phone/token/payment/provider ID or raw URL.
- Security headers/CSP-compatible assets; no third-party script beyond approved payment redirect, Turnstile and consented analytics.

## Test matrix and completion

- Component/view tests for every state/error/localization and safe escaping.
- Contract mock Playwright first; after Agent 07, real Laravel/PostgreSQL journey with no mocked application API.
- Desktop and 390×844: search → quote → optional service/promotion → consent → hold → hosted checkout handoff → pending return → authoritative approval → confirmation/document.
- Failure flows: unavailable, stale quote, voucher invalid, bot rejected, rate limited, double click, offline/reload, checkout failure/expiry, late paid-needs-review, refund pending.
- Accessibility automation plus manual keyboard/focus/screen-reader landmarks; no critical/high axe findings if axe is added.
- Token/PII checks in DOM, URL, storage, console, network logs and analytics payload.
- Next marketing CTA reaches the correct property route and preserves only safe attribution.
- Disabled/not-ready property fails closed with a stable unavailable page; authorized staff diagnostics are never exposed publicly.
- Run web lint/typecheck/build/Playwright plus universal integrated gates after rebase. Evidence includes screenshots at three viewports, trace for one failure, and no secrets/PII.

## Primary references

- [WCAG 2.2](https://www.w3.org/TR/WCAG22/)
- [Playwright web server](https://playwright.dev/docs/test-webserver), [projects](https://playwright.dev/docs/test-projects), and [authentication/state](https://playwright.dev/docs/auth)
- [Laravel Blade](https://laravel.com/docs/13.x/blade), [localization](https://laravel.com/docs/13.x/localization), and [CSRF](https://laravel.com/docs/13.x/csrf)
- [Next.js production checklist](https://nextjs.org/docs/app/guides/production-checklist)
