# ADR — same-origin Laravel public booking UI

Status: accepted for P3-07
Date: 2026-08-20

## Decision

Build the direct-booking UI as a same-origin Laravel server-rendered/Livewire surface. The public Next.js marketing app links into it. Do not add a Next.js BFF, cross-origin credential/token exchange, duplicate session or duplicate availability/pricing client.

## Why

Laravel already owns availability, quotes, reservations, guest evidence, payment requests, tenant/property isolation, throttles, CSRF/session controls, schedules and audit. A same-origin flow has one session/cookie/security boundary, can progressively enhance forms, and keeps secrets/PII out of the marketing bundle and `NEXT_PUBLIC_*` environment variables. It is the shortest reliable path for accessible server validation, no-store state pages and generic recovery.

Next.js remains an excellent public marketing renderer, but a BFF would add another public mutation surface, request canonicalization/idempotency hop, CORS/CSRF policy, secret store, correlation/retry boundary and opportunity to expose server data to client components. Current Next guidance says Route Handlers and Server Actions must be treated as public APIs with authorization at the data boundary and minimal DTOs; those controls already exist in Laravel. See [Next.js authentication/authorization](https://nextjs.org/docs/app/guides/authentication), [`use server` security](https://nextjs.org/docs/app/api-reference/directives/use-server), and the [production checklist](https://nextjs.org/docs/app/guides/production-checklist).

## Consequences

- Agent 08 builds Blade/Livewire pages and links `apps/web` to the property booking route.
- Browser code renders server totals/bookability and never calculates them.
- CSRF applies to same-origin form mutations in addition to idempotency/state-version controls. See [Laravel CSRF protection](https://laravel.com/docs/13.x/csrf).
- Published media may be CDN-backed, but booking state responses remain no-store.
- A future BFF requires a new reviewed ADR showing a concrete need and preserving every contract/security invariant with end-to-end tests.

## Rejected alternatives

- **Next client directly calling Laravel cross-origin:** expands token/CORS/CSRF exposure and complicates accessible server-error recovery.
- **Next BFF proxy:** duplicates mutation/idempotency/authorization handling without current product value.
- **Client-only SPA authority:** cannot be inventory, price, consent, payment or confirmation authority and fails the offline/tamper threat model.
