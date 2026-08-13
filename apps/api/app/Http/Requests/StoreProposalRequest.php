<?php

namespace App\Http\Requests;

use App\Models\Program;
use Illuminate\Validation\Validator;

class StoreProposalRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'property_id' => ['required', 'uuid', $this->tenantExists('properties')],
            'program_id' => ['nullable', 'uuid', $this->tenantExists('programs')],
            'primary_guest_id' => ['nullable', 'uuid', $this->tenantExists('guests')],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'adults' => ['required', 'integer', 'min:1', 'max:1000'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'currency' => ['required', 'string', 'size:3'],
            'title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'tax_minor' => ['sometimes', 'integer', 'min:0', 'max:999999999999'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity_thousandths' => ['required', 'integer', 'min:1', 'max:100000000'],
            'lines.*.unit_amount_minor' => ['required', 'integer', 'min:-999999999999', 'max:999999999999'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $programId = $this->input('program_id');
            $propertyId = $this->input('property_id');
            if ($programId && $propertyId && ! Program::query()->whereKey($programId)->where('property_id', $propertyId)->exists()) {
                $validator->errors()->add('program_id', 'The program does not belong to the selected property.');
            }
        }];
    }
}
