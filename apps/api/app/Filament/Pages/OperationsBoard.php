<?php

namespace App\Filament\Pages;

use App\Enums\MembershipRole;
use App\Enums\ReservationStatus;
use App\Enums\TaskStatus;
use App\Filament\Resources\OperationalTasks\OperationalTaskResource;
use App\Models\OperationalTask;
use App\Models\Reservation;
use App\Services\Projections\OperationsProjectionService;
use App\Support\Projections\StaffProjectionVisibility;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;

class OperationsBoard extends Page
{
    protected string $view = 'filament.pages.operations-board';

    protected static ?string $title = 'Operations board';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-group';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return app(TenantContext::class)->membership()?->role?->canScheduleOperations() === true;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $context = app(TenantContext::class);
        $membership = $context->membership();
        $role = $membership?->role;
        abort_unless($role instanceof MembershipRole, 403);

        $timezone = $context->tenant()->timezone;
        $now = CarbonImmutable::now($timezone);
        $start = $now->startOfDay()->utc();
        $end = $now->addDay()->startOfDay()->utc();
        $managerialRoles = [MembershipRole::Administrator, MembershipRole::Manager, MembershipRole::Operations];
        $propertyId = $membership?->property_id;

        $tasks = OperationalTask::query()
            ->with(['assignee:id,name', 'property:id,name'])
            ->when($propertyId, fn (Builder $query) => $query->where('property_id', $propertyId))
            ->when(! in_array($role, $managerialRoles, true), fn (Builder $query) => $this->applyTaskRoleScope($query, $role))
            ->whereNotIn('status', [TaskStatus::Done, TaskStatus::Cancelled])
            ->orderByRaw("case priority when 'urgent' then 1 when 'high' then 2 when 'normal' then 3 else 4 end")
            ->orderBy('due_at')
            ->limit(50)
            ->get();

        $reservations = Reservation::query()
            ->with(['primaryGuest:id,first_name,last_name', 'property:id,name'])
            ->when($propertyId, fn (Builder $query) => $query->where('property_id', $propertyId))
            ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn])
            ->where(function (Builder $query) use ($start, $end): void {
                $query->whereBetween('starts_at', [$start, $end])
                    ->orWhereBetween('ends_at', [$start, $end]);
            })
            ->orderBy('starts_at')
            ->get();
        $visibility = app(StaffProjectionVisibility::class);
        $projection = app(OperationsProjectionService::class)->build(request()->user());

        return [
            'timezone' => $timezone,
            'date' => $now,
            'tasks' => $tasks,
            'arrivals' => $reservations
                ->filter(fn (Reservation $reservation) => $reservation->starts_at->greaterThanOrEqualTo($start) && $reservation->starts_at->lessThan($end))
                ->map(fn (Reservation $reservation): array => $this->reservationItem($reservation, $visibility))
                ->values(),
            'departures' => $reservations
                ->filter(fn (Reservation $reservation) => $reservation->ends_at->greaterThanOrEqualTo($start) && $reservation->ends_at->lessThan($end))
                ->map(fn (Reservation $reservation): array => $this->reservationItem($reservation, $visibility))
                ->values(),
            'overdue' => $tasks->filter(fn (OperationalTask $task): bool => $task->due_at?->isPast() === true)->count(),
            'operations' => $projection,
        ];
    }

    private function applyTaskRoleScope(Builder $query, MembershipRole $role): void
    {
        $query->where(function (Builder $tasks) use ($role): void {
            $tasks->where('assignee_id', auth()->id())
                ->orWhere(function (Builder $unassigned) use ($role): void {
                    $unassigned->whereNull('assignee_id')
                        ->where(function (Builder $roleTasks) use ($role): void {
                            $roleTasks
                                ->whereHas('programTaskTemplate', fn (Builder $template) => $template->where('assignee_role', $role->value))
                                ->orWhere('metadata->assignee_role', $role->value)
                                ->orWhere('metadata->role', $role->value)
                                ->orWhere('metadata->team', $role->value);
                        });
                });
        });
    }

    /** @return array<string, mixed> */
    private function reservationItem(Reservation $reservation, StaffProjectionVisibility $visibility): array
    {
        return [
            'reference' => $reservation->confirmation_number,
            'guest' => $visibility->canSeeGuestIdentity() && $reservation->primaryGuest
                ? trim("{$reservation->primaryGuest->first_name} {$reservation->primaryGuest->last_name}")
                : null,
            'property' => $reservation->property->name,
            'starts_at' => $reservation->starts_at,
            'ends_at' => $reservation->ends_at,
            'party' => $reservation->adults + $reservation->children,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newTask')
                ->label('New task')
                ->icon('heroicon-m-plus')
                ->url(OperationalTaskResource::getUrl('create'))
                ->visible(OperationalTaskResource::canCreate()),
            Action::make('allTasks')
                ->label('All tasks')
                ->url(OperationalTaskResource::getUrl()),
        ];
    }
}
