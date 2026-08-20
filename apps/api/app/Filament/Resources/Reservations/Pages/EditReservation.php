<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\Reservation;
use App\Services\ReservationCompanionService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;

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
        $record = $this->reservation();

        return [
            ...Arr::only($data, ['primary_guest_id', 'source', 'notes']),
            'revision' => $record->revision + 1,
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->reservation();
        $data['companion_guest_ids'] = $record->guests
            ->reject(fn ($guest): bool => $guest->id === $record->primary_guest_id)
            ->pluck('id')
            ->all();

        return $data;
    }

    protected function afterSave(): void
    {
        $record = $this->reservation();
        app(ReservationCompanionService::class)->replace(
            $record,
            collect($this->data['companion_guest_ids'] ?? [])->values()
                ->map(fn (string $guestId): array => ['guest_id' => $guestId])->all(),
            $record->revision,
            auth()->id(),
        );
    }

    private function reservation(): Reservation
    {
        $record = $this->getRecord();
        abort_unless($record instanceof Reservation, 404);

        return $record;
    }
}
