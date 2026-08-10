<?php

namespace App\Http\Requests;

use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

class UpdateResourceRequest extends StoreResourceRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['property_id', 'name', 'code', 'type', 'capacity'] as $field) {
            $rules[$field][0] = 'sometimes';
        }
        $rules['code'] = [
            'sometimes', 'string', 'max:50',
            Rule::unique('resources')->where('tenant_id', app(TenantContext::class)->id())->ignore($this->route('resource')),
        ];

        return $rules;
    }
}
