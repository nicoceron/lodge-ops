<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StorePaymentRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'reservation_id' => ['required', 'uuid', $this->tenantExists('reservations')],
            'method' => ['required', Rule::in(['bank_transfer', 'cash', 'card', 'other'])],
            'provider' => ['nullable', 'string', 'max:80'],
            'provider_reference' => ['nullable', 'string', 'max:200', 'required_with:provider'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'captured' => ['sometimes', 'boolean'],
            'metadata' => ['nullable', 'array', 'max:100'],
        ];
    }
}
