<?php

namespace App\Policies;

use App\Models\SettlementReportRow;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

class SettlementReportRowPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canViewFinance($user);
    }

    public function view(User $user, SettlementReportRow $row): bool
    {
        $propertyScope = app(TenantContext::class)->membership()?->property_id;
        if ($propertyScope !== null && $row->property_id === null) {
            return false;
        }

        return $this->canViewFinance($user, $row);
    }
}
