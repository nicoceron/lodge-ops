<?php

namespace App\Http\Middleware;

use App\Models\DirectBookingPropertySetting;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ResolveDirectBookingProperty
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = (string) $request->route('propertySlug');
        $candidate = DirectBookingPropertySetting::withoutGlobalScopes()
            ->where('public_slug', $slug)
            ->first(['id', 'tenant_id']);
        if ($candidate === null) {
            return $this->unavailable($request);
        }
        $tenant = Tenant::query()->whereKey($candidate->tenant_id)->where('is_active', true)->first();
        if ($tenant === null) {
            return $this->unavailable($request);
        }

        $this->context->set($tenant);
        try {
            $setting = DirectBookingPropertySetting::query()->with('property')->find($candidate->id);
            if ($setting === null || ! $setting->property->is_active) {
                abort(404);
            }
            $request->attributes->set('direct_booking_setting', $setting);

            return $next($request);
        } finally {
            $this->context->clear();
        }
    }

    private function unavailable(Request $request): Response
    {
        $correlation = $request->header('X-Correlation-ID');
        if (! is_string($correlation) || preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $correlation) !== 1) {
            $correlation = (string) Str::uuid();
        }

        return response()->json(['error' => [
            'code' => 'booking_unavailable',
            'message' => 'Direct booking is temporarily unavailable.',
            'correlation_id' => $correlation,
            'retryable' => true,
        ]], 503, [
            'Cache-Control' => 'no-store, private',
            'X-Correlation-ID' => $correlation,
            'Retry-After' => '60',
        ]);
    }
}
