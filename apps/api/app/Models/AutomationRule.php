<?php

namespace App\Models;

class AutomationRule extends TenantModel
{
    protected function casts(): array
    {
        return ['conditions' => 'array', 'actions' => 'array', 'is_active' => 'boolean', 'last_ran_at' => 'immutable_datetime'];
    }
}
