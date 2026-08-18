<?php

namespace App\Filament\Resources\TaxRules\Pages;

use App\Filament\Resources\TaxRules\TaxRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTaxRules extends ManageRecords
{
    protected static string $resource = TaxRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
