<?php

namespace App\Http\Requests;

class StoreDepositRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'reservation_id' => ['required', 'uuid', $this->tenantExists('reservations')],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'due_at' => ['nullable', 'date'],
        ];
    }
}
