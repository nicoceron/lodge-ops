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
            'evidence_url' => ['nullable', 'url:http,https', 'max:2000'],
            'evidence_note' => ['nullable', 'string', 'max:5000'],
            'deposit_id' => ['nullable', 'uuid', $this->tenantExists('deposits')],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'captured' => ['sometimes', 'boolean'],
            'metadata' => ['nullable', 'array', 'max:100'],
        ];
    }
}
