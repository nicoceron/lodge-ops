<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Enums\ReservationStatus;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\ReservationGuest;
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

    protected function afterCreate(): void
    {
        $this->syncGuests();
    }

    private function syncGuests(): void
    {
        $guestIds = array_values(array_unique(array_filter([
            $this->record->primary_guest_id,
            ...($this->data['companion_guest_ids'] ?? []),
        ])));
        foreach ($guestIds as $guestId) {
            ReservationGuest::query()->create([
                'reservation_id' => $this->record->id,
                'guest_id' => $guestId,
                'role' => $guestId === $this->record->primary_guest_id ? 'primary' : 'guest',
            ]);
        }
    }
}
