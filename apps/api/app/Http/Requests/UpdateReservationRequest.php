<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

class UpdateReservationRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'primary_guest_id' => ['sometimes', 'nullable', 'uuid', $this->tenantExists('guests')],
            'guest_ids' => ['sometimes', 'array', 'max:50'],
            'guest_ids.*' => ['uuid', 'distinct', $this->tenantExists('guests')],
            'source' => ['sometimes', 'nullable', 'string', 'max:50'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ([
                'property_id', 'program_id', 'starts_at', 'ends_at', 'adults', 'children',
                'currency', 'subtotal_minor', 'tax_minor', 'total_minor', 'allocations',
            ] as $field) {
                if ($this->exists($field)) {
                    $validator->errors()->add($field, 'Use the guarded amendment or reallocation command for this field.');
                }
            }
        }];
    }
}
