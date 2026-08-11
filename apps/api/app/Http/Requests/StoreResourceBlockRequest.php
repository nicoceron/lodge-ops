<?php

namespace App\Http\Requests;

class StoreResourceBlockRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'resource_id' => ['required', 'uuid', $this->tenantExists('resources')],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
