<?php

namespace App\Policies;

use App\Enums\ReportExportKind;
use App\Models\Property;
use App\Models\ReportExport;
use App\Models\User;

class ReportExportPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canViewFinance($user) || $this->canManageOperations($user);
    }

    public function view(User $user, ReportExport $export): bool
    {
        return $this->finance($export->kind) ? $this->canViewFinance($user, $export) : $this->canManageOperations($user, $export);
    }

    public function createFor(User $user, ReportExportKind $kind, Property $property): bool
    {
        return $this->finance($kind) ? $this->canViewFinance($user, $property) : $this->canManageOperations($user, $property);
    }

    public function retry(User $user, ReportExport $export): bool
    {
        return $this->view($user, $export);
    }

    public function download(User $user, ReportExport $export): bool
    {
        return $this->view($user, $export);
    }

    public function purge(User $user, ReportExport $export): bool
    {
        return $this->view($user, $export);
    }

    private function finance(ReportExportKind $kind): bool
    {
        return in_array($kind, [ReportExportKind::Revenue, ReportExportKind::PaymentsDepositsRefunds, ReportExportKind::CostsMarginCommissions], true);
    }
}
