<?php

namespace App\Http\Requests\GuestPortal;

use Illuminate\Foundation\Http\FormRequest;

class AcknowledgeDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reservation_id' => ['prohibited'],
            'document_slug' => ['required', 'string', 'max:80'],
            'document_version' => ['required', 'string', 'max:40'],
            'document_hash' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'signature' => ['required', 'string', 'min:3', 'max:200'],
            'accepted' => ['accepted'],
        ];
    }
}
