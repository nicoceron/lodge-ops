<?php

namespace App\Policies;

class ReportExportPolicy extends TenantResourcePolicy
{
    protected ?string $viewCapability = 'canViewFinance';

    protected string $writeCapability = 'canManageMoney';
}
