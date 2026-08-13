<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

class StoreReservationRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'property_id' => ['required', 'uuid', $this->tenantExists('properties')],
            'program_id' => ['nullable', 'uuid', $this->tenantExists('programs')],
            'primary_guest_id' => ['nullable', 'uuid', $this->tenantExists('guests')],
            'guest_ids' => ['nullable', 'array', 'max:50'],
            'guest_ids.*' => ['uuid', 'distinct', $this->tenantExists('guests')],
            'source' => ['nullable', 'string', 'max:50'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'adults' => ['required', 'integer', 'min:1', 'max:1000'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'currency' => ['required', 'string', 'size:3', 'uppercase'],
            'subtotal_minor' => ['sometimes', 'integer', 'min:0'],
            'tax_minor' => ['sometimes', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'allocations' => ['nullable', 'array', 'max:100'],
            'allocations.*.requested_category_id' => ['nullable', 'uuid', $this->tenantExists('resource_categories')],
            'allocations.*.resource_id' => ['nullable', 'uuid', $this->tenantExists('resources')],
            'allocations.*.service_occurrence_id' => ['nullable', 'uuid', $this->tenantExists('service_occurrences')],
            'allocations.*.starts_at' => ['required_with:allocations', 'date'],
            'allocations.*.ends_at' => ['required_with:allocations', 'date'],
            'allocations.*.quantity' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ($this->input('allocations', []) as $index => $allocation) {
                if (empty($allocation['requested_category_id']) && empty($allocation['resource_id']) && empty($allocation['service_occurrence_id'])) {
                    $validator->errors()->add("allocations.{$index}", 'An allocation must request a category, assign a resource, or target a service occurrence.');
                }

                if (isset($allocation['starts_at'], $allocation['ends_at']) && strtotime($allocation['ends_at']) <= strtotime($allocation['starts_at'])) {
                    $validator->errors()->add("allocations.{$index}.ends_at", 'The allocation end must be after its start.');
                }
            }
        }];
    }
}
