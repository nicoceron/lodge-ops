<?php

namespace App\Http\Requests;

use App\Enums\FolioLineType;
use Illuminate\Validation\Rule;

class StoreFolioLineRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([FolioLineType::Charge->value, FolioLineType::Adjustment->value])],
            'description' => ['required', 'string', 'max:500'],
            'quantity_thousandths' => ['required', 'integer', 'min:1', 'max:100000000'],
            'unit_amount_minor' => ['required', 'integer', 'min:-999999999999', 'max:999999999999', 'not_in:0'],
            'metadata' => ['nullable', 'array', 'max:100'],
        ];
    }
}
