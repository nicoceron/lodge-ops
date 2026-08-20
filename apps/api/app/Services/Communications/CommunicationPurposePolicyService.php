<?php

namespace App\Services\Communications;

use App\Enums\CommunicationPurpose;
use App\Models\CommunicationPurposePolicy;
use DomainException;

final class CommunicationPurposePolicyService
{
    public function approved(CommunicationPurpose|string $purpose): CommunicationPurposePolicy
    {
        $purpose = $purpose instanceof CommunicationPurpose
            ? $purpose : CommunicationPurpose::tryFrom($purpose);
        if ($purpose === null) {
            throw new DomainException('The communication purpose is not approved.');
        }

        $policies = CommunicationPurposePolicy::query()
            ->where('purpose', $purpose->value)->where('is_active', true)
            ->whereNotNull('approved_at')->get();
        if ($policies->count() !== 1) {
            throw new DomainException('The communication purpose has no single active approved policy.');
        }

        return $policies->firstOrFail();
    }
}
