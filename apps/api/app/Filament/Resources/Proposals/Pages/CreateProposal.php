<?php

namespace App\Filament\Resources\Proposals\Pages;

use App\Filament\Resources\Proposals\ProposalResource;
use App\Services\BookingQuoteService;
use App\Services\ProposalService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProposal extends CreateRecord
{
    protected static string $resource = ProposalResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $quote = app(BookingQuoteService::class)->create([
            'property_id' => $data['property_id'],
            'resource_category_id' => $data['resource_category_id'],
            'rate_plan_id' => $data['rate_plan_id'],
            'resource_id' => $data['resource_id'] ?? null,
            'program_id' => $data['program_id'] ?? null,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'adults' => (int) $data['adults'],
            'children' => (int) $data['children'],
            'infants' => (int) ($data['infants'] ?? 0),
        ]);

        return app(ProposalService::class)->createDraft(
            [
                ...$data,
                'booking_quote_id' => $quote->id,
                'currency' => $quote->currency,
                'tax_minor' => $quote->tax_minor,
                'lines' => [],
            ],
            auth()->id(),
        );
    }
}
