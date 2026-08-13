<?php

namespace App\Http\Resources;

use App\Services\FolioService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuestPortalFolioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $summary = app(FolioService::class)->summary($this->resource);

        return [
            'confirmation_number' => $this->confirmation_number,
            'currency' => $this->currency,
            'status' => $summary['status'],
            'is_final' => $summary['status'] === 'closed',
            'lines' => $this->folioLines->sortBy('posted_at')->values()->map(fn ($line): array => [
                'type' => $line->type->value,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_amount_minor' => $line->unit_amount_minor,
                'net_amount_minor' => $line->net_amount_minor,
                'tax_amount_minor' => $line->tax_amount_minor,
                'gross_amount_minor' => $line->gross_amount_minor,
                'amount_minor' => $line->amount_minor,
                'posted_at' => $line->posted_at->toIso8601String(),
            ]),
            'booked_net_minor' => $summary['booked_net_minor'],
            'booked_tax_minor' => $summary['booked_tax_minor'],
            'booked_total_minor' => $summary['booked_total_minor'],
            'balance_minor' => $summary['balance_minor'],
        ];
    }
}
