<?php

namespace App\Filament\Resources\Proposals;

use App\Models\Proposal;

final class ProposalFormData
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public static function forService(array $data): array
    {
        return collect($data)->only(['inquiry_source', 'primary_guest_id', 'expires_at', 'title', 'notes'])->all();
    }

    /** @return array<string, mixed> */
    public static function fromRecord(Proposal $proposal): array
    {
        return [
            ...$proposal->only([
                'property_id',
                'primary_guest_id',
                'starts_at',
                'ends_at',
                'adults',
                'children',
                'currency',
                'tax_minor',
                'expires_at',
                'inquiry_source',
            ]),
            'title' => data_get($proposal->snapshot, 'title'),
            'program_id' => data_get($proposal->snapshot, 'program_id'),
            'notes' => data_get($proposal->snapshot, 'notes'),
            'lines' => collect(data_get($proposal->snapshot, 'lines', []))->map(fn (array $line): array => [
                'description' => $line['description'],
                'quantity' => $line['quantity_thousandths'] / 1000,
                'unit_amount_minor' => $line['unit_amount_minor'],
            ])->all(),
        ];
    }
}
