<?php

namespace App\Http\Requests\GuestPortal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePreArrivalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reservation_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'profile' => ['required', 'array'],
            'profile.preferred_name' => ['required', 'string', 'max:100'],
            'profile.email' => ['required', 'email:rfc', 'max:255'],
            'profile.mobile' => ['required', 'string', 'max:40'],
            'profile.emergency_name' => ['required', 'string', 'max:150'],
            'profile.emergency_phone' => ['required', 'string', 'max:40'],
            'travel' => ['required', 'array'],
            'travel.arrival_method' => ['required', Rule::in(['flight', 'car', 'other'])],
            'travel.arrival_reference' => ['nullable', 'string', 'max:100'],
            'travel.arrival_time' => ['required', 'date'],
            'travel.departure_reference' => ['required', 'string', 'max:100'],
            'travel.departure_time' => ['required', 'date', 'after:travel.arrival_time'],
            'preferences' => ['required', 'array'],
            'preferences.dietary_style' => ['required', 'string', 'max:100'],
            'preferences.allergies' => ['nullable', 'string', 'max:1000'],
            'preferences.accessibility' => ['nullable', 'string', 'max:2000'],
            'preferences.medical_consent' => ['accepted'],
        ];
    }
}
