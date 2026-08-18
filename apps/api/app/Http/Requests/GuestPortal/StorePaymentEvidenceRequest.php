<?php

namespace App\Http\Requests\GuestPortal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StorePaymentEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reservation_id' => ['prohibited'],
            'amount_minor' => ['nullable', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'size:3'],
            'transfer_reference' => ['nullable', 'string', 'max:200'],
            'evidence' => ['required', File::types(['pdf', 'jpg', 'jpeg', 'png'])->max(10 * 1024)],
        ];
    }
}
