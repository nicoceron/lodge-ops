<?php

namespace App\Filament\Resources\CancellationPolicies\Pages;

use App\Filament\Resources\CancellationPolicies\CancellationPolicyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCancellationPolicies extends ManageRecords
{
    protected static string $resource = CancellationPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
