<?php

namespace App\Http\Requests;

use App\Enums\AllocationStatus;
use Illuminate\Validation\Rule;

class StoreAllocationRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'requested_category_id' => ['nullable', 'uuid', $this->tenantExists('resource_categories')],
            'resource_id' => ['nullable', 'uuid', $this->tenantExists('resources')],
            'service_occurrence_id' => ['nullable', 'uuid', $this->tenantExists('service_occurrences')],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'status' => ['sometimes', Rule::enum(AllocationStatus::class)],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            if (! $this->filled('requested_category_id') && ! $this->filled('resource_id') && ! $this->filled('service_occurrence_id')) {
                $validator->errors()->add('requested_category_id', 'Request a category, assign a resource, or select a scheduled activity.');
            }
        }];
    }
}
