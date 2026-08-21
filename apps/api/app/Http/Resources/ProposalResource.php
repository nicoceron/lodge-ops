<?php

namespace App\Http\Resources;

use App\Models\Proposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProposalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $proposal = $this->resource;
        if (! $proposal instanceof Proposal) {
            return [];
        }
        $status = $this->status->value;
        $bookingQuoteId = $proposal->getAttribute('booking_quote_id');

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'version' => $this->version,
            'status' => $status,
            'property_id' => $this->property_id,
            'primary_guest_id' => $this->primary_guest_id,
            'reservation_id' => $this->reservation_id,
            'booking_quote_id' => $bookingQuoteId,
            'pricing_mode' => $bookingQuoteId === null ? 'legacy_manual_read_only' : 'server_quote',
            'convertible' => $bookingQuoteId !== null && $status === 'sent',
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'adults' => $this->adults,
            'children' => $this->children,
            'currency' => $this->currency,
            'total_minor' => $this->total_minor,
            'tax_minor' => $this->tax_minor,
            'snapshot' => $this->snapshot,
            'expires_at' => $this->expires_at,
            'sent_at' => $this->sent_at,
            'accepted_at' => $this->accepted_at,
            'converted_at' => $this->converted_at,
            'created_at' => $this->created_at,
        ];
    }
}
