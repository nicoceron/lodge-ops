<?php

namespace App\Filament\Resources\DepositPolicies\Pages;

use App\Filament\Resources\DepositPolicies\DepositPolicyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDepositPolicies extends ManageRecords
{
    protected static string $resource = DepositPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
