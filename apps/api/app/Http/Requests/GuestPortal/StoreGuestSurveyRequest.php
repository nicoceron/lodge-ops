<?php

namespace App\Http\Requests\GuestPortal;

use Illuminate\Foundation\Http\FormRequest;

class StoreGuestSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reservation_id' => ['prohibited'],
            'stay_rating' => ['required', 'integer', 'between:1,5'],
            'guide_rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:5000'],
            'share_with_team' => ['required', 'boolean'],
        ];
    }
}
