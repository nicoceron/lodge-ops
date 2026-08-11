<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveFilamentTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        abort_unless($tenant instanceof Tenant && $request->user()?->canAccessTenant($tenant), 403);
        $this->context->set($tenant);

        $membership = Membership::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->where('is_active', true)
            ->firstOrFail();
        $this->context->set($tenant, $membership);

        return $next($request);
    }
}
