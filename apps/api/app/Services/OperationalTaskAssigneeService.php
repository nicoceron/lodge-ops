<?php

namespace App\Services;

use App\Enums\MembershipRole;
use App\Models\Membership;
use App\Models\OperationalTask;
use Illuminate\Validation\ValidationException;

final class OperationalTaskAssigneeService
{
    public function assertEligible(OperationalTask $task, int $assigneeId): void
    {
        $membership = Membership::query()
            ->where('user_id', $assigneeId)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('property_id')->orWhere('property_id', $task->property_id))
            ->first();

        if ($membership === null) {
            throw ValidationException::withMessages(['assignee_id' => 'The assignee must have an active membership for this property.']);
        }

        $requiredRole = $this->requiredRole($task);
        if ($requiredRole !== null && ! $this->roleMatches($membership->role, $requiredRole)) {
            throw ValidationException::withMessages(['assignee_id' => "This task requires an active {$requiredRole} assignee at the reservation property."]);
        }
    }

    private function requiredRole(OperationalTask $task): ?string
    {
        $task->loadMissing('programTaskTemplate');

        $role = data_get($task->programTaskTemplate, 'assignee_role')
            ?? data_get($task->metadata, 'checklist_role')
            ?? data_get($task->metadata, 'assignee_role')
            ?? data_get($task->metadata, 'role')
            ?? data_get($task->metadata, 'team');

        return is_string($role) && trim($role) !== '' ? mb_strtolower(trim($role)) : null;
    }

    private function roleMatches(MembershipRole $actual, string $required): bool
    {
        if (in_array($actual, [MembershipRole::Administrator, MembershipRole::Manager, MembershipRole::Operations], true)) {
            return true;
        }

        return match ($required) {
            'guide' => $actual === MembershipRole::Guide,
            'kitchen' => $actual === MembershipRole::Kitchen,
            'housekeeping' => $actual === MembershipRole::Housekeeping,
            'operations' => false,
            default => $actual->value === $required,
        };
    }
}
