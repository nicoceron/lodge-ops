<?php

namespace App\Http\Requests;

use App\Enums\PaymentChannel;
use Illuminate\Validation\Rule;

class StoreFrontDeskPaymentRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::enum(PaymentChannel::class), Rule::in(['cash', 'bank_transfer', 'external_terminal', 'manual_other'])],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'deposit_id' => ['nullable', 'uuid', $this->tenantExists('deposits')],
            'processor_alias' => ['nullable', 'string', 'max:80'],
            'merchant_account_alias' => ['nullable', 'string', 'max:120'],
            'terminal_identifier' => ['nullable', 'string', 'max:80'],
            'transaction_reference' => ['nullable', 'string', 'max:160'],
            'authorization_reference' => ['nullable', 'string', 'max:160'],
            'batch_reference' => ['nullable', 'string', 'max:120'],
            'card_brand' => ['nullable', 'string', 'max:40'],
            'card_last_four' => ['nullable', 'regex:/^\d{4}$/'],
            'note' => ['nullable', 'string', 'max:500'],
            'luhn_false_positive_fields' => ['sometimes', 'array', 'max:3'],
            'luhn_false_positive_fields.*' => ['required', 'string', Rule::in(['transaction_reference', 'authorization_reference', 'batch_reference'])],
            'luhn_false_positive_justification' => ['nullable', 'string', 'min:20', 'max:500', 'required_with:luhn_false_positive_fields'],
            'provider' => ['prohibited'],
            'provider_reference' => ['prohibited'],
            'captured' => ['prohibited'],
            'currency' => ['prohibited'],
            'metadata' => ['prohibited'],
        ];
    }
}
