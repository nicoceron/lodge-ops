<?php

namespace App\Filament\Resources\CommissionAccruals\Pages;

use App\Filament\Resources\CommissionAccruals\CommissionAccrualResource;
use App\Models\Reservation;
use App\Services\CommissionService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCommissionAccrual extends CreateRecord
{
    protected static string $resource = CommissionAccrualResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(CommissionService::class)->accrue(
            Reservation::query()->findOrFail($data['reservation_id']),
            $data['payee_type'],
            $data['payee_name'],
            (int) $data['rate_basis_points'],
        );
    }
}
