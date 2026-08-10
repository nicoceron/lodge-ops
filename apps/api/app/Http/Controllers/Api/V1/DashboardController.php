<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReservationStatus;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\OperationalTask;
use App\Models\Reservation;
use App\Models\Resource;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, TenantContext $context): JsonResponse
    {
        $this->authorize('viewAny', Reservation::class);
        $now = CarbonImmutable::now($context->tenant()->timezone);
        $start = $now->startOfDay()->utc();
        $end = $now->endOfDay()->addMicrosecond()->utc();

        $arrivals = Reservation::query()->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn])->where('starts_at', '>=', $start)->where('starts_at', '<', $end)->count();
        $departures = Reservation::query()->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn])->where('ends_at', '>=', $start)->where('ends_at', '<', $end)->count();
        $inHouse = Reservation::query()->where('status', ReservationStatus::CheckedIn)->where('starts_at', '<=', $now->utc())->where('ends_at', '>', $now->utc())->count();
        $activeResources = Resource::query()->where('is_active', true)->count();

        return response()->json([
            'data' => [
                'date' => $now->toDateString(),
                'timezone' => $context->tenant()->timezone,
                'arrivals' => $arrivals,
                'departures' => $departures,
                'in_house' => $inHouse,
                'active_resources' => $activeResources,
                'open_tasks' => OperationalTask::query()->whereNotIn('status', [TaskStatus::Done, TaskStatus::Cancelled])->count(),
                'occupancy_percent' => $activeResources > 0 ? round(($inHouse / $activeResources) * 100, 1) : 0.0,
            ],
        ]);
    }
}
