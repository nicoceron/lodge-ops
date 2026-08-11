<?php

namespace App\Http\Requests;

use App\Models\Program;
use Illuminate\Validation\Validator;

class StoreServiceOccurrenceRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'program_id' => ['required', 'uuid', $this->tenantExists('programs')],
            'property_id' => ['required', 'uuid', $this->tenantExists('properties')],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'is_cancelled' => ['sometimes', 'boolean'],
            'meeting_point' => ['nullable', 'string', 'max:255'],
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
