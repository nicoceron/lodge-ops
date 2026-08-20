<?php

namespace App\Services\Communications;

use App\Models\Communication;
use App\Models\CommunicationPreference;
use App\Models\Guest;

final class CommunicationConsentService
{
    public function __construct(private readonly CommunicationPurposePolicyService $policies) {}

    public function allows(Communication $communication, Guest $guest, ?string $propertyId): bool
    {
        $purpose = $communication->purpose ?: 'transactional';
        $policy = $this->policies->approved($purpose);
        if (! $policy->requires_consent) {
            return true;
        }

        $preference = CommunicationPreference::query()
            ->where('guest_id', $guest->id)
            ->where('channel', $communication->channel)
            ->where('purpose', $purpose)
            ->when(
                $propertyId,
                fn ($query) => $query->where(fn ($scope) => $scope->where('property_id', $propertyId)->orWhereNull('property_id')),
                fn ($query) => $query->whereNull('property_id'),
            )
            ->orderByRaw('property_id is null')
            ->latest('recorded_at')
            ->first();

        return $preference?->is_allowed === true && $preference->withdrawn_at === null;
    }
}
