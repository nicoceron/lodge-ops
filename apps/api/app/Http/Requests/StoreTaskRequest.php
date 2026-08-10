<?php

namespace App\Http\Requests;

use App\Enums\TaskStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'property_id' => ['required', 'uuid', $this->tenantExists('properties')],
            'reservation_id' => ['nullable', 'uuid', $this->tenantExists('reservations')],
            'assignee_id' => ['nullable', 'integer', Rule::exists('memberships', 'user_id')->where('tenant_id', app(TenantContext::class)->id())->where('is_active', true)],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'due_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array', 'max:100'],
        ];
    }
}
