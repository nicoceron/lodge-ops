<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateProposalRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'property_id' => ['sometimes', 'filled', 'prohibited'],
            'booking_quote_id' => ['sometimes', 'filled', 'prohibited'],
            'inquiry_source' => ['sometimes', 'nullable', Rule::in(['email', 'whatsapp', 'phone', 'walk_in', 'partner', 'web', 'other_approved'])],
            'program_id' => ['sometimes', 'filled', 'prohibited'],
            'primary_guest_id' => ['sometimes', 'nullable', 'uuid', $this->tenantExists('guests')],
            'starts_at' => ['sometimes', 'filled', 'prohibited'],
            'ends_at' => ['sometimes', 'filled', 'prohibited'],
            'adults' => ['sometimes', 'filled', 'prohibited'],
            'children' => ['sometimes', 'filled', 'prohibited'],
            'currency' => ['sometimes', 'filled', 'prohibited'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'tax_minor' => ['sometimes', 'filled', 'prohibited'],
            'total_minor' => ['sometimes', 'filled', 'prohibited'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'lines' => ['sometimes', 'filled', 'prohibited'],
        ];
    }
}
