<?php

namespace App\Http\Controllers;

use App\Models\CalendarFeed;
use App\Models\Tenant;
use App\Services\CalendarFeedService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Response;

class CalendarFeedController extends Controller
{
    public function __invoke(string $token, CalendarFeedService $service, TenantContext $context): Response
    {
        abort_unless(strlen($token) === 64, 404);
        $candidate = CalendarFeed::withoutGlobalScopes()
            ->where('token_hash', hash('sha256', $token))
            ->where('is_active', true)
            ->first(['id', 'tenant_id']);
        abort_if($candidate === null, 404);

        $tenant = Tenant::query()->whereKey($candidate->tenant_id)->where('is_active', true)->firstOrFail();
        $context->set($tenant);
        try {
            $feed = CalendarFeed::query()->with(['resource', 'property'])->findOrFail($candidate->id);
            $calendar = $service->render($feed);
            $feed->update(['last_accessed_at' => now()]);

            return response($calendar, 200, [
                'Content-Type' => 'text/calendar; charset=utf-8',
                'Content-Disposition' => 'inline; filename="'.str($feed->name)->slug().'.ics"',
                'Cache-Control' => 'private, max-age=300',
            ]);
        } finally {
            $context->clear();
        }
    }
}
