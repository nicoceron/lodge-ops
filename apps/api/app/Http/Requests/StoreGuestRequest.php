<?php

namespace App\Http\Requests;

use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

class StoreGuestRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:254', Rule::unique('guests')->where('tenant_id', app(TenantContext::class)->id())],
            'phone' => ['nullable', 'string', 'max:40'],
            'document_type' => ['nullable', 'string', 'max:40'],
            'document_number' => ['nullable', 'string', 'max:100'],
            'language' => ['nullable', 'string', 'max:12'],
            'preferences' => ['nullable', 'array', 'max:50'],
            'marketing_consent' => ['sometimes', 'boolean'],
        ];
    }
}
