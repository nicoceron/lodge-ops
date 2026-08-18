<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Filament\Resources\Reservations\ReservationResource;
use App\Services\BookingQuoteService;
use App\Services\CommitBookingQuote;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateReservation extends CreateRecord
{
    protected static string $resource = ReservationResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $quote = app(BookingQuoteService::class)->create($data);

        return app(CommitBookingQuote::class)->handle(
            $quote,
            $data['primary_guest_id'] ?? null,
            [
                'first_name' => $data['guest_first_name'] ?? null,
                'last_name' => $data['guest_last_name'] ?? null,
                'email' => $data['guest_email'] ?? null,
                'phone' => $data['guest_phone'] ?? null,
                'language' => $data['guest_language'] ?? null,
                'dietary' => $data['guest_dietary'] ?? null,
            ],
            $data['companion_guest_ids'] ?? [],
            $data['source'] ?? null,
            $data['notes'] ?? null,
        );
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Priced reservation hold created';
    }
}
