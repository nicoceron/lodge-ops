<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreProposalRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'property_id' => ['required', 'uuid', $this->tenantExists('properties')],
            'booking_quote_id' => ['required', 'uuid', $this->tenantExists('booking_quotes')],
            'inquiry_source' => ['nullable', Rule::in(['email', 'whatsapp', 'phone', 'walk_in', 'partner', 'web', 'other_approved'])],
            'program_id' => ['prohibited'],
            'primary_guest_id' => ['nullable', 'uuid', $this->tenantExists('guests')],
            'starts_at' => ['prohibited'],
            'ends_at' => ['prohibited'],
            'adults' => ['prohibited'],
            'children' => ['prohibited'],
            'currency' => ['prohibited'],
            'title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'tax_minor' => ['prohibited'],
            'total_minor' => ['prohibited'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'lines' => ['prohibited'],
        ];
    }
}
