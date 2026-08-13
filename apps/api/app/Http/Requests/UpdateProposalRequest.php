<?php

namespace App\Http\Requests;

use App\Models\Program;
use App\Models\Proposal;
use Illuminate\Validation\Validator;

class UpdateProposalRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'property_id' => ['sometimes', 'uuid', $this->tenantExists('properties')],
            'program_id' => ['sometimes', 'nullable', 'uuid', $this->tenantExists('programs')],
            'primary_guest_id' => ['sometimes', 'nullable', 'uuid', $this->tenantExists('guests')],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after:starts_at'],
            'adults' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'tax_minor' => ['sometimes', 'integer', 'min:0', 'max:999999999999'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'lines' => ['sometimes', 'array', 'min:1', 'max:200'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity_thousandths' => ['required', 'integer', 'min:1', 'max:100000000'],
            'lines.*.unit_amount_minor' => ['required', 'integer', 'min:-999999999999', 'max:999999999999'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $proposal = $this->route('proposal');
            $propertyId = $this->input('property_id', $proposal instanceof Proposal ? $proposal->property_id : null);
            $programId = $this->input('program_id', $proposal instanceof Proposal ? data_get($proposal->snapshot, 'program_id') : null);
            if ($programId && $propertyId && ! Program::query()->whereKey($programId)->where('property_id', $propertyId)->exists()) {
                $validator->errors()->add('program_id', 'The program does not belong to the selected property.');
            }
        }];
    }
}
