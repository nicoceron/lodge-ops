<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->header('X-Tenant-ID');

        if (! is_string($tenantId) || ! Str::isUuid($tenantId)) {
            return new JsonResponse(['message' => 'A valid X-Tenant-ID header is required.'], 400);
        }

        $tenant = Tenant::query()->whereKey($tenantId)->where('is_active', true)->first();

        if ($tenant === null) {
            return new JsonResponse(['message' => 'Tenant not found.'], 404);
        }

        $this->context->set($tenant);

        try {
            $membership = Membership::query()
                ->where('user_id', $request->user()->getAuthIdentifier())
                ->where('is_active', true)
                ->first();

            if ($membership === null) {
                return new JsonResponse(['message' => 'You do not have access to this tenant.'], 403);
            }

            $this->context->set($tenant, $membership);

            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
