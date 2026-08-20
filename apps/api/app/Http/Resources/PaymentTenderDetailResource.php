<?php

namespace App\Http\Resources;

use App\Models\Payment;
use App\Models\PaymentTenderDetail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PaymentTenderDetail */
class PaymentTenderDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $finance = $request->user()?->can('viewFinance', Payment::class) === true;

        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'payment_id' => $this->payment_id,
            'deposit_id' => $this->deposit_id,
            'channel' => $this->channel->value,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'state' => $this->state,
            'processor_alias' => $finance ? $this->processor_alias : null,
            'merchant_account_alias' => $finance ? $this->merchant_account_alias : null,
            'terminal_identifier' => $finance ? $this->terminal_identifier : null,
            'transaction_reference' => $finance ? $this->transaction_reference : null,
            'authorization_reference' => $finance ? $this->authorization_reference : null,
            'batch_reference' => $finance ? $this->batch_reference : null,
            'card_brand' => $this->card_brand,
            'card_last_four' => $this->card_last_four,
            'duplicate_of_id' => $finance ? $this->duplicate_of_id : null,
            'review_reason' => $finance ? $this->review_reason : null,
            'note' => $finance ? $this->note : null,
            'received_at' => $this->received_at,
            'business_date' => $this->business_date,
            'payment' => $this->whenLoaded('payment', fn () => new PaymentResource($this->payment)),
        ];
    }
}
