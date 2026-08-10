<?php

namespace App\Filament\Resources\CommunicationSuppressions\Pages;

use App\Filament\Resources\CommunicationSuppressions\CommunicationSuppressionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCommunicationSuppressions extends ManageRecords
{
    protected static string $resource = CommunicationSuppressionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(function (array $data): array {
                    $recipient = mb_strtolower(trim($data['recipient']));
                    unset($data['recipient']);
                    $data['recipient_hash'] = hash('sha256', $recipient);

                    return $data;
                }),
        ];
    }
}
