<?php

namespace App\Policies;

use App\Models\MessageTemplate;
use App\Models\User;

class MessageTemplatePolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageConfiguration($user);
    }

    public function view(User $user, MessageTemplate $template): bool
    {
        return $this->canManageConfiguration($user, $template);
    }

    public function create(User $user): bool
    {
        return $this->canManageConfiguration($user);
    }

    public function update(User $user, MessageTemplate $template): bool
    {
        return $this->canManageConfiguration($user, $template);
    }
}
