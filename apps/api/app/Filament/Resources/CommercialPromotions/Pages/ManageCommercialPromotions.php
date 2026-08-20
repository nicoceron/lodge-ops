<?php

namespace App\Filament\Resources\CommercialPromotions\Pages;

use App\Filament\Resources\CommercialPromotions\CommercialPromotionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCommercialPromotions extends ManageRecords
{
    protected static string $resource = CommercialPromotionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
