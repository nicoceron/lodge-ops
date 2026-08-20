<?php

namespace App\Filament\Resources\Vouchers\Pages;

use App\Filament\Resources\Vouchers\VoucherResource;
use App\Models\CommercialPromotion;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateVoucher extends CreateRecord
{
    protected static string $resource = VoucherResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $promotion = CommercialPromotion::query()->whereKey($data['commercial_promotion_id'])->where('state', 'published')->where('requires_code', true)->firstOrFail();
        if ($promotion->property_id !== null && $promotion->property_id !== $data['property_id']) {
            throw ValidationException::withMessages(['commercial_promotion_id' => 'The promotion is outside this property.']);
        }
        $data['state'] = 'active';

        return $data;
    }
}
