<?php

namespace App\Http\Requests;

use App\Enums\ResourceType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

class StoreResourceRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'property_id' => ['required', 'uuid', $this->tenantExists('properties')],
            'user_id' => ['nullable', 'integer', Rule::exists('memberships', 'user_id')->where(fn ($query) => $query
                ->where('tenant_id', app(TenantContext::class)->id())
                ->where('is_active', true))],
            'name' => ['required', 'string', 'max:160'],
            'code' => ['required', 'string', 'max:50', Rule::unique('resources')->where('tenant_id', app(TenantContext::class)->id())],
            'type' => ['required', Rule::enum(ResourceType::class)],
            'capacity' => ['required', 'integer', 'min:1', 'max:10000'],
            'is_buyout' => ['sometimes', 'boolean'],
            'attributes' => ['nullable', 'array', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
