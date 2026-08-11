<?php

namespace App\Http\Middleware;

use App\Models\GuestPortalAccessToken;
use App\Models\Tenant;
use App\Services\GuestPortalTokenService;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveGuestPortalSession
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $request->bearerToken();

        if (! is_string($plainToken) && $request->hasSession()) {
            $plainToken = $request->session()->get('guest_portal_session_token');
        }

        if (! is_string($plainToken) || strlen($plainToken) < 32 || strlen($plainToken) > 256) {
            return $this->unauthenticated($request);
        }

        $access = GuestPortalAccessToken::withoutGlobalScopes()
            ->where('session_hash', GuestPortalTokenService::hash($plainToken))
            ->first();

        if (
            $access === null
            || $access->revoked_at !== null
            || $access->exchanged_at === null
            || $access->session_expires_at === null
            || $access->session_expires_at->isPast()
        ) {
            return $this->unauthenticated($request);
        }

        $tenant = Tenant::query()->whereKey($access->tenant_id)->where('is_active', true)->first();

        if ($tenant === null) {
            return $this->unauthenticated($request);
        }

        $this->context->set($tenant);
        $request->attributes->set('guest_portal_access', $access);

        try {
            $access->forceFill(['last_used_at' => now()])->save();

            return $next($request);
        } finally {
            $this->context->clear();
        }
    }

    private function unauthenticated(Request $request): Response
    {
        if (! $request->expectsJson() && $request->hasSession()) {
            $request->session()->forget('guest_portal_session_token');

            return redirect()->route('guest.portal.unavailable');
        }

        return new JsonResponse(['message' => 'This guest portal session is unavailable.'], 401);
    }
}
