<?php

namespace App\Policies;

use App\Models\ChecklistTemplate;
use App\Models\User;

class ChecklistTemplatePolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageConfiguration($user);
    }

    public function view(User $user, ChecklistTemplate $template): bool
    {
        return $this->canManageConfiguration($user, $template);
    }

    public function create(User $user): bool
    {
        return $this->canManageConfiguration($user);
    }

    public function update(User $user, ChecklistTemplate $template): bool
    {
        return $this->canManageConfiguration($user, $template);
    }

    public function delete(User $user, ChecklistTemplate $template): bool
    {
        return $this->canManageConfiguration($user, $template) && $template->latest_version === 0;
    }
}
