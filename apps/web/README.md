# LodgeOps public website

This Next.js application is deliberately public-only. It contains the LodgeOps homepage, feature, pricing, and security content.

It must not contain:

- staff authentication or account recovery;
- tenant selection or protected staff routes;
- reservation, guest, operations, finance, or settings workspaces;
- a Laravel API proxy or browser-side Sanctum session handling;
- guest magic-link workflows.

Those surfaces live in Laravel:

- Filament staff application: `http://localhost:8000/manage`
- Laravel guest portal: `http://localhost:8000/guest/access/{one-time-token}`

## Commands

```bash
npm ci
npm run dev
npm run lint
npm run typecheck
npm run build
npm run e2e
```

Set `NEXT_PUBLIC_MANAGE_URL` to the absolute Filament panel URL. The public site's sign-in and application CTAs link there directly.
