<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Filament\Resources\Reservations\ReservationResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditReservation extends EditRecord
{
    protected static string $resource = ReservationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['status']);
        $data['currency'] = strtoupper($data['currency']);
        $data['total_minor'] = $data['subtotal_minor'] + $data['tax_minor'];
        $data['revision'] = $this->record->revision + 1;

        return $data;
    }
}
