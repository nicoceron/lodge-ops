<?php

namespace App\Policies;

class SurveyPolicy extends TenantResourcePolicy
{
    protected ?string $viewCapability = 'canManageGuests';

    protected string $writeCapability = 'canManageGuests';
}
