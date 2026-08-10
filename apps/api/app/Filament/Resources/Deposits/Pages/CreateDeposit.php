<?php

namespace App\Filament\Resources\Deposits\Pages;

use App\Filament\Resources\Deposits\DepositResource;
use App\Models\Reservation;
use App\Services\DepositService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDeposit extends CreateRecord
{
    protected static string $resource = DepositResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(DepositService::class)->create(
            Reservation::query()->findOrFail($data['reservation_id']),
            (int) $data['amount_minor'],
            $data['due_at'] ?? null,
        );
    }
}
