<?php

namespace App\Filament\Pages;

use App\Enums\AllocationStatus;
use App\Enums\MembershipRole;
use App\Enums\ResourceType;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\OperationalTask;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\ResourceBlock;
use App\Models\ServiceOccurrence;
use App\Models\User;
use App\Services\Projections\CalendarProjectionService;
use App\Support\Projections\StaffProjectionVisibility;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class MasterCalendar extends Page
{
    protected string $view = 'filament.pages.master-calendar';

    protected static ?string $title = 'Master calendar';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 1;

    public string $start = '';

    public string $end = '';

    public ?string $propertyId = null;

    public function mount(): void
    {
        $timezone = app(TenantContext::class)->tenant()->timezone;
        $this->start = CarbonImmutable::now($timezone)->startOfDay()->toDateString();
        $this->end = CarbonImmutable::now($timezone)->addDays(14)->endOfDay()->toDateString();
        $this->propertyId = app(TenantContext::class)->membership()?->property_id;
    }

    public static function canAccess(): bool
    {
        return in_array(app(TenantContext::class)->membership()?->role, [
            MembershipRole::Administrator,
            MembershipRole::Manager,
            MembershipRole::Sales,
            MembershipRole::Operations,
            MembershipRole::Guide,
            MembershipRole::Viewer,
        ], true);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $context = app(TenantContext::class);
        $timezone = $context->tenant()->timezone;
        $start = CarbonImmutable::parse($this->start ?: 'today', $timezone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($this->end ?: $this->start, $timezone)->endOfDay()->utc();

        if ($end->lessThanOrEqualTo($start) || $start->diffInDays($end) > 92) {
            $end = $start->addDays(14);
        }

        $membership = $context->membership();
        $propertyId = $membership?->property_id ?? $this->propertyId;
        $isGuide = $membership?->role === MembershipRole::Guide;
        $guideResourceIds = $isGuide
            ? Resource::query()
                ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
                ->where('type', ResourceType::Guide)
                ->where('user_id', auth()->id())
                ->where('is_active', true)
                ->pluck('id')
            : collect();

        $reservations = Reservation::query()
            ->with([
                'primaryGuest:id,first_name,last_name',
                'property:id,name',
                'program:id,name,display_color',
                'allocations.resource:id,is_buyout,attributes',
            ])
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->when($isGuide, fn ($query) => $query->whereHas('allocations', fn ($query) => $query
                ->whereIn('resource_id', $guideResourceIds)
                ->where('status', '!=', AllocationStatus::Released)))
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->orderBy('starts_at')
            ->get();

        $events = $this->events($reservations, $start, $end, $propertyId, $isGuide, $guideResourceIds);
        $user = request()->user();
        abort_unless($user instanceof User, 403);
        $projection = app(CalendarProjectionService::class)->build($start, $end, $user, $propertyId);
        $localStart = $start->timezone($timezone)->startOfDay();
        $localEnd = $end->timezone($timezone)->startOfDay();
        $days = collect(range(0, $localStart->diffInDays($localEnd)))
            ->map(function (int $offset) use ($events, $localStart): array {
                $day = $localStart->addDays($offset);
                $dayStart = $day->utc();
                $dayEnd = $day->addDay()->utc();

                return [
                    'date' => $day,
                    'events' => $events->filter(fn (array $event): bool => $event['starts_at']->lessThan($dayEnd) && $event['ends_at']->greaterThanOrEqualTo($dayStart))->values(),
                ];
            });

        return [
            'timezone' => $timezone,
            'properties' => $membership?->property_id === null
                ? $context->tenant()->properties()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : collect(),
            'events' => $events,
            'days' => $days,
            'programs' => $events
                ->filter(fn (array $event): bool => filled($event['program']) && filled($event['color']))
                ->unique('program')
                ->map(fn (array $event): array => ['name' => $event['program'], 'color' => $event['color']])
                ->values(),
            'buyouts' => $events->where('is_buyout', true)->values(),
            'allocationSummary' => $projection['summary'],
            'resources' => collect($projection['resources']),
        ];
    }

    /**
     * @param  Collection<int, Reservation>  $reservations
     * @param  Collection<int, string>  $guideResourceIds
     * @return Collection<int, array<string, mixed>>
     */
    private function events(
        Collection $reservations,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?string $propertyId,
        bool $isGuide,
        Collection $guideResourceIds,
    ): Collection {
        $visibility = app(StaffProjectionVisibility::class);
        /** @var Collection<int, array<string, mixed>> $events */
        $events = collect();
        $reservations->each(fn (Reservation $reservation) => $events->push([
            'type' => 'Reservation',
            'title' => $visibility->canSeeGuestIdentity() && $reservation->primaryGuest
                ? trim("{$reservation->primaryGuest->first_name} {$reservation->primaryGuest->last_name}")
                : $reservation->confirmation_number,
            'reference' => $reservation->confirmation_number,
            'starts_at' => $reservation->starts_at,
            'ends_at' => $reservation->ends_at,
            'status' => $reservation->status->value,
            'property' => $reservation->property->name,
            'program' => $reservation->program?->name,
            'color' => $this->calendarColor($reservation->program?->display_color),
            'is_buyout' => $reservation->allocations->contains(
                fn ($allocation): bool => $allocation->resource?->isBuyout() === true,
            ),
            'url' => ReservationResource::getUrl('view', ['record' => $reservation]),
        ]));

        ResourceBlock::query()
            ->with('resource:id,property_id,name')
            ->whereHas('resource', fn ($query) => $query->when($propertyId, fn ($query) => $query->where('property_id', $propertyId)))
            ->when($isGuide, fn ($query) => $query->whereIn('resource_id', $guideResourceIds))
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->get()
            ->each(fn (ResourceBlock $block) => $events->push([
                'type' => 'Resource block',
                'title' => $block->reason,
                'reference' => $block->resource->name,
                'starts_at' => $block->starts_at,
                'ends_at' => $block->ends_at,
                'status' => 'blocked',
                'property' => $block->resource->property?->name ?? '',
                'program' => null,
                'color' => null,
                'is_buyout' => false,
                'url' => null,
            ]));

        ServiceOccurrence::query()
            ->with(['program:id,name,display_color', 'property:id,name'])
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->when($isGuide, fn ($query) => $query->whereHas('allocations', fn ($query) => $query
                ->whereIn('resource_id', $guideResourceIds)
                ->where('status', '!=', AllocationStatus::Released)))
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->get()
            ->each(fn (ServiceOccurrence $occurrence) => $events->push([
                'type' => 'Activity',
                'title' => $occurrence->program->name,
                'reference' => $occurrence->meeting_point,
                'starts_at' => $occurrence->starts_at,
                'ends_at' => $occurrence->ends_at,
                'status' => $occurrence->is_cancelled ? 'cancelled' : 'scheduled',
                'property' => $occurrence->property->name,
                'program' => $occurrence->program->name,
                'color' => $this->calendarColor($occurrence->program->display_color),
                'is_buyout' => false,
                'url' => null,
            ]));

        OperationalTask::query()
            ->with('property:id,name')
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->when($isGuide, fn ($query) => $query->where('assignee_id', auth()->id()))
            ->whereNotNull('due_at')
            ->where('due_at', '>=', $start)
            ->where('due_at', '<', $end)
            ->get()
            ->each(fn (OperationalTask $task) => $events->push([
                'type' => 'Task',
                'title' => $task->title,
                'reference' => $task->priority,
                'starts_at' => $task->due_at,
                'ends_at' => $task->due_at,
                'status' => $task->status->value,
                'property' => $task->property?->name ?? '',
                'program' => null,
                'color' => null,
                'is_buyout' => false,
                'url' => null,
            ]));

        return $events->sortBy('starts_at')->values();
    }

    private function calendarColor(?string $color): ?string
    {
        return is_string($color) && preg_match('/^#[0-9a-f]{6}$/i', $color) === 1
            ? strtoupper($color)
            : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newReservation')
                ->label('New reservation')
                ->icon('heroicon-m-plus')
                ->url(ReservationResource::getUrl('create'))
                ->visible(ReservationResource::canCreate()),
        ];
    }
}
