<?php

namespace App\Services\Communications;

use App\Enums\CommunicationPurpose;
use App\Models\CommunicationPreference;
use App\Models\Guest;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CommunicationPreferenceService
{
    public function __construct(private readonly CommunicationPurposePolicyService $policies) {}

    public function record(
        Guest $guest,
        CommunicationPurpose $purpose,
        bool $allowed,
        string $source,
        ?Property $property = null,
        ?User $actor = null,
    ): CommunicationPreference {
        $policy = $this->policies->approved($purpose);

        return DB::transaction(function () use ($guest, $purpose, $allowed, $source, $property, $actor, $policy): CommunicationPreference {
            $preference = CommunicationPreference::query()->firstOrNew([
                'property_id' => $property?->id,
                'guest_id' => $guest->id,
                'channel' => 'email',
                'purpose' => $purpose->value,
            ]);
            $preference->forceFill([
                'is_allowed' => $allowed,
                'source' => mb_substr($source, 0, 80),
                'policy_version' => $policy->version,
                'recorded_at' => now(),
                'withdrawn_at' => $allowed ? null : now(),
                'metadata' => array_filter(['actor_id' => $actor?->id]),
            ])->save();

            return $preference;
        }, 3);
    }
}
