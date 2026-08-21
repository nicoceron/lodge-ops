<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipRole;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Projections\CalendarProjectionService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            'resource_kind' => ['nullable', Rule::in(['place', 'asset', 'crew'])],
            'reservation_status' => ['nullable', 'string', 'max:32'],
            'program_id' => ['nullable', 'uuid'],
            'boundary' => ['nullable', Rule::in(['arrival', 'departure'])],
            'attention' => ['nullable', Rule::in(['conflicted', 'unassigned'])],
        ]);
        /** @var User $user */
        $user = $request->user();
        $propertyId = $context->propertyScopeId() ?? ($validated['property_id'] ?? null);
        if ($propertyId !== null && ! $context->canAccessProperty($propertyId)) {
            abort(403);
        }
        $timezone = $propertyId === null
            ? $context->tenant()->timezone
            : Property::query()->whereKey($propertyId)->firstOrFail()->timezone;
        $start = $this->boundary($validated['start'], $timezone);
        $end = $this->boundary($validated['end'], $timezone);

        $result = $projection->build(
            $start,
            $end,
            $user,
            $propertyId,
        );
        $events = collect($result['data']);
        if (isset($validated['reservation_status'])) {
            $events = $events->filter(fn (array $event): bool => $event['type'] !== 'reservation' || $event['status'] === $validated['reservation_status']);
        }
        if (isset($validated['program_id'])) {
            $events = $events->where('program_id', $validated['program_id']);
        }
        if (isset($validated['resource_kind'])) {
            $ids = collect($result['resources'])->where('kind', $validated['resource_kind'])->pluck('id');
            $events = $events->filter(fn (array $event): bool => collect($event['resource_ids'])->intersect($ids)->isNotEmpty());
            $result['resources'] = collect($result['resources'])->where('kind', $validated['resource_kind'])->values();
            $result['allocations'] = collect($result['allocations'])->whereIn('resource_id', $ids)->values();
        }
        if (($validated['attention'] ?? null) === 'conflicted') {
            $events = $events->where('type', 'reservation')->whereIn('id', $result['summary']['hard_conflict_reservation_ids']);
        } elseif (($validated['attention'] ?? null) === 'unassigned') {
            $events = $events->where('type', 'reservation')->filter(fn (array $event): bool => collect($event['resource_ids'])->isEmpty());
        }
        if (isset($validated['boundary'])) {
            $field = $validated['boundary'] === 'arrival' ? 'start' : 'end';
            $boundaryStart = $start;
            $boundaryEnd = $end;
            $events = $events->where('type', 'reservation')->filter(function (array $event) use ($field, $boundaryStart, $boundaryEnd): bool {
                $instant = CarbonImmutable::parse($event[$field]);

                return $instant->greaterThanOrEqualTo($boundaryStart) && $instant->lessThan($boundaryEnd);
            });
        }
        $result['data'] = $events->values();

        return response()->json($result);
    }

    private function boundary(string $value, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse($value, $timezone)->utc();
    }
}
