<?php

namespace App\Http\Requests;

class UpdateAllocationRequest extends StoreAllocationRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['starts_at', 'ends_at'] as $field) {
            $rules[$field][0] = 'sometimes';
        }
        $rules['resource_id'] = ['sometimes', 'nullable', 'uuid', $this->tenantExists('resources')];
        $rules['service_occurrence_id'] = ['sometimes', 'nullable', 'uuid', $this->tenantExists('service_occurrences')];

        return $rules;
    }
}
