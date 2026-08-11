<?php

namespace App\Services;

use App\Enums\MembershipRole;
use App\Models\OperationalTask;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

class OperationalTaskAccess
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function scope(Builder $query, User $user, MembershipRole $role): Builder
    {
        $propertyId = $this->tenantContext->membership()?->property_id;
        $query->when($propertyId, fn (Builder $scope) => $scope->where('property_id', $propertyId));

        if ($role->canScheduleOperations()) {
            return $query;
        }

        return $query->where(function (Builder $tasks) use ($user, $role): void {
            $tasks->where('assignee_id', $user->id)
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

    public function allows(User $user, OperationalTask $task, MembershipRole $role): bool
    {
        if ($role->canScheduleOperations() || (int) $task->assignee_id === $user->id) {
            return true;
        }

        if ($task->assignee_id !== null) {
            return false;
        }

        $task->loadMissing('programTaskTemplate');

        return $task->programTaskTemplate?->assignee_role === $role->value
            || in_array($role->value, array_filter([
                data_get($task->metadata, 'assignee_role'),
                data_get($task->metadata, 'role'),
                data_get($task->metadata, 'team'),
            ], 'is_string'), true);
    }
}
