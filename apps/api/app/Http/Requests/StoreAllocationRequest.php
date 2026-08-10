<?php

namespace App\Http\Requests;

use App\Enums\AllocationStatus;
use Illuminate\Validation\Rule;

class StoreAllocationRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'resource_id' => ['nullable', 'uuid', 'required_without:service_occurrence_id', $this->tenantExists('resources')],
            'service_occurrence_id' => ['nullable', 'uuid', 'required_without:resource_id', $this->tenantExists('service_occurrences')],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'status' => ['sometimes', Rule::enum(AllocationStatus::class)],
        ];
    }
}
