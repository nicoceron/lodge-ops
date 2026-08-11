<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipRole;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Projections\CalendarProjectionService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        CalendarProjectionService $projection,
    ): JsonResponse {
        $this->authorize('viewAny', Reservation::class);
        abort_unless(in_array($context->membership()?->role, [
            MembershipRole::Administrator,
            MembershipRole::Manager,
            MembershipRole::Sales,
            MembershipRole::Operations,
            MembershipRole::Guide,
            MembershipRole::Viewer,
        ], true), 403);
        $validated = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'property_id' => ['nullable', 'uuid'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return response()->json($projection->build(
            CarbonImmutable::parse($validated['start'])->utc(),
            CarbonImmutable::parse($validated['end'])->utc(),
            $user,
            $validated['property_id'] ?? null,
        ));
    }
}
