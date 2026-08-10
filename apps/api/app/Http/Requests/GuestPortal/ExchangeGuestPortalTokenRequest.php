<?php

namespace App\Http\Requests\GuestPortal;

use Illuminate\Foundation\Http\FormRequest;

class ExchangeGuestPortalTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['token' => ['required', 'string']];
    }
}
