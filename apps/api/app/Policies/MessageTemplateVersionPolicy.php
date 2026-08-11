<?php

namespace App\Policies;

use App\Models\MessageTemplateVersion;
use App\Models\User;

class MessageTemplateVersionPolicy extends TenantPolicy
{
    public function create(User $user): bool
    {
        return $this->canManageConfiguration($user);
    }

    public function update(User $user, MessageTemplateVersion $version): bool
    {
        return $this->canManageConfiguration($user, $version);
    }

    public function publish(User $user, MessageTemplateVersion $version): bool
    {
        return $this->update($user, $version);
    }
}
