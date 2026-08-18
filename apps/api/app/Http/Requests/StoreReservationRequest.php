<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

class StoreReservationRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'quote_id' => ['required', 'uuid', $this->tenantExists('booking_quotes')],
            'primary_guest_id' => ['nullable', 'uuid', $this->tenantExists('guests')],
            'companion_guest_ids' => ['nullable', 'array', 'max:50'],
            'companion_guest_ids.*' => ['uuid', 'distinct', $this->tenantExists('guests')],
            'guest_first_name' => ['required_without:primary_guest_id', 'nullable', 'string', 'max:100'],
            'guest_last_name' => ['nullable', 'string', 'max:100'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'guest_phone' => ['nullable', 'string', 'max:40'],
            'guest_language' => ['nullable', 'string', 'max:12'],
            'guest_dietary' => ['nullable', 'string', 'max:500'],
            'source' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['currency', 'subtotal_minor', 'tax_minor', 'total_minor', 'allocations'] as $field) {
                if ($this->exists($field)) {
                    $validator->errors()->add($field, 'Reservation pricing and allocation come from the committed quote.');
                }
            }
        }];
    }
}
