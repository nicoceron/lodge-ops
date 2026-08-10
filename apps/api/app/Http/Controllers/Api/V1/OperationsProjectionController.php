<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReservationStatus;
use App\Enums\ResourceType;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\Allocation;
use App\Models\OperationalTask;
use App\Models\Reservation;
use App\Models\ServiceOccurrence;
use App\Support\Projections\StaffProjectionVisibility;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class OperationsProjectionController extends Controller
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        StaffProjectionVisibility $visibility,
    ): JsonResponse {
        $this->authorize('viewOperations', OperationalTask::class);
        $now = CarbonImmutable::now($context->tenant()->timezone);
        $start = $now->startOfDay()->utc();
        $end = $now->addDay()->startOfDay()->utc();
        $tomorrowEnd = $now->addDays(2)->startOfDay()->utc();
        $activeStatuses = [ReservationStatus::Confirmed, ReservationStatus::CheckedIn];

        $tasks = OperationalTask::query()
            ->with('assignee:id,name')
            ->where(function ($query) use ($end): void {
                $query->whereNull('due_at')->orWhere('due_at', '<', $end);
            })
            ->orderByRaw("case priority when 'urgent' then 1 when 'high' then 2 when 'normal' then 3 else 4 end")
            ->orderBy('due_at')
            ->limit(30)
            ->get();
        $operationalReservations = Reservation::query()
            ->with('primaryGuest:id,first_name,last_name,preferences')
            ->whereIn('status', $activeStatuses)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->orderBy('starts_at')
            ->get();
        $arrivals = $operationalReservations->filter(
            fn (Reservation $reservation) => $reservation->starts_at->greaterThanOrEqualTo($start)
                && $reservation->starts_at->lessThan($end),
        );
        $departures = $operationalReservations->filter(
            fn (Reservation $reservation) => $reservation->ends_at->greaterThanOrEqualTo($start)
                && $reservation->ends_at->lessThan($end),
        );
        $stayovers = $operationalReservations->filter(
            fn (Reservation $reservation) => $reservation->starts_at->lessThan($start)
                && $reservation->ends_at->greaterThan($end),
        );

        $occurrences = ServiceOccurrence::query()
            ->with(['program:id,name', 'allocations.resource:id,name,type', 'allocations.reservation:id,primary_guest_id,adults,children,confirmation_number'])
            ->where('starts_at', '>=', $end)
            ->where('starts_at', '<', $tomorrowEnd)
            ->where('is_cancelled', false)
            ->orderBy('starts_at')
            ->get();

        $taskItems = $tasks->map(fn (OperationalTask $task): array => [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority,
            'due_at' => $task->due_at?->toIso8601String(),
            'owner_initials' => $this->initials($task->assignee?->name),
        ]);

        return response()->json([
            'data' => [
                'date' => $now->toDateString(),
                'timezone' => $context->tenant()->timezone,
                'readiness' => [
                    'complete' => $tasks->whereIn('status', [TaskStatus::Done, TaskStatus::Cancelled])->count(),
                    'total' => $tasks->count(),
                    'open' => $tasks->whereNotIn('status', [TaskStatus::Done, TaskStatus::Cancelled])->count(),
                ],
                'tasks' => $taskItems,
                'arrivals' => $arrivals->map(fn (Reservation $reservation) => $this->arrival($reservation, $visibility))->values(),
                'kitchen' => [
                    'guest_count' => $operationalReservations->sum(fn (Reservation $reservation) => $reservation->adults + $reservation->children),
                    'restrictions' => $this->restrictions($operationalReservations),
                    'identity_restricted' => true,
                ],
                'guide_assignments' => $occurrences->map(fn (ServiceOccurrence $occurrence) => $this->guideAssignment($occurrence))->values(),
                'housekeeping' => [
                    'arrivals' => $arrivals->count(),
                    'turnovers' => $departures->count(),
                    'stayovers' => $stayovers->count(),
                    'focus' => $tasks->firstWhere('priority', 'urgent')?->title
                        ?? $tasks->firstWhere('priority', 'high')?->title,
                ],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function arrival(Reservation $reservation, StaffProjectionVisibility $visibility): array
    {
        $arrival = [
            'id' => $reservation->id,
            'confirmation_number' => $reservation->confirmation_number,
            'starts_at' => $reservation->starts_at->toIso8601String(),
            'ends_at' => $reservation->ends_at->toIso8601String(),
            'party_size' => $reservation->adults + $reservation->children,
            'status' => $reservation->status->value,
        ];

        if ($visibility->canSeeGuestIdentity()) {
            $arrival['guest_name'] = $reservation->primaryGuest
                ? trim("{$reservation->primaryGuest->first_name} {$reservation->primaryGuest->last_name}")
                : null;
        }

        if ($visibility->canSeeDietaryDetails()) {
            $arrival['dietary'] = $this->dietaryLabels($reservation->primaryGuest?->preferences);
        }

        return $arrival;
    }

    /** @param Collection<int, Reservation> $reservations @return list<array<string, mixed>> */
    private function restrictions(Collection $reservations): array
    {
        return $reservations
            ->flatMap(fn (Reservation $reservation) => $this->dietaryLabels($reservation->primaryGuest?->preferences))
            ->countBy()
            ->map(fn (int $count, string $label): array => [
                'label' => $label,
                'count' => $count,
                'serious' => str_contains(strtolower($label), 'allerg') || str_contains(strtolower($label), 'severe'),
            ])
            ->values()
            ->all();
    }

    /** @param array<string, mixed>|null $preferences @return list<string> */
    private function dietaryLabels(?array $preferences): array
    {
        if ($preferences === null) {
            return [];
        }

        $values = collect([
            data_get($preferences, 'dietary'),
            data_get($preferences, 'dietary_requirements'),
            data_get($preferences, 'allergies'),
        ])->filter()->flatMap(function (mixed $value): array {
            if (is_array($value)) {
                return array_values(array_filter($value, 'is_string'));
            }

            return is_string($value) ? preg_split('/[,;]+/', $value) ?: [] : [];
        });

        return $values
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->unique(fn (string $value) => strtolower($value))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function guideAssignment(ServiceOccurrence $occurrence): array
    {
        $guide = $occurrence->allocations->first(
            fn (Allocation $allocation) => $allocation->resource?->type === ResourceType::Guide,
        )?->resource;
        $reservations = $occurrence->allocations->pluck('reservation')->filter()->unique('id');

        return [
            'id' => $occurrence->id,
            'guide' => $guide?->name,
            'program' => $occurrence->program->name,
            'starts_at' => $occurrence->starts_at->toIso8601String(),
            'party_size' => $reservations->sum(fn (Reservation $reservation) => $reservation->adults + $reservation->children),
            'status' => $guide ? 'confirmed' : 'action_needed',
        ];
    }

    private function initials(?string $name): string
    {
        if (! $name) {
            return '—';
        }

        return collect(preg_split('/\s+/', trim($name)) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }
}
