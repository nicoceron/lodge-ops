<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Enums\ReservationStatus;
use App\Filament\Resources\Reservations\ReservationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateReservation extends CreateRecord
{
    protected static string $resource = ReservationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = ReservationStatus::Draft;
        $data['currency'] = strtoupper($data['currency']);
        $data['total_minor'] = $data['subtotal_minor'] + $data['tax_minor'];

        return $data;
    }
}
