<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\ReservationGuest;
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

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['companion_guest_ids'] = $this->record->guests()
            ->where('guests.id', '!=', $this->record->primary_guest_id)
            ->pluck('guests.id')
            ->all();

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->guests()->detach();
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
