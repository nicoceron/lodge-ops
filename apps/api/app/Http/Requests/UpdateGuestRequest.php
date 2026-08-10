<?php

namespace App\Http\Requests;

use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

class UpdateGuestRequest extends StoreGuestRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['first_name'][0] = 'sometimes';
        $rules['email'] = [
            'sometimes', 'nullable', 'email:rfc', 'max:254',
            Rule::unique('guests')->where('tenant_id', app(TenantContext::class)->id())->ignore($this->route('guest')),
        ];

        return $rules;
    }
}
