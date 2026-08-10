<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuestPortalFolioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'confirmation_number' => $this->confirmation_number,
            'currency' => $this->currency,
            'is_final' => $this->ends_at->isPast(),
            'lines' => $this->folioLines->sortBy('posted_at')->values()->map(fn ($line): array => [
                'type' => $line->type->value,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_amount_minor' => $line->unit_amount_minor,
                'amount_minor' => $line->amount_minor,
                'posted_at' => $line->posted_at->toIso8601String(),
            ]),
            'balance_minor' => $this->folioLines->sum('amount_minor'),
        ];
    }
}
