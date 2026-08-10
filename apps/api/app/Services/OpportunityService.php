<?php

namespace App\Services;

use App\Models\Opportunity;
use App\Models\Proposal;
use DomainException;

class OpportunityService
{
    public function transition(Opportunity $opportunity, string $nextStage, ?string $lostReason = null): Opportunity
    {
        $allowed = match ($opportunity->stage) {
            'inquiry' => ['qualified', 'lost'],
            'qualified' => ['proposal', 'lost'],
            'proposal' => ['won', 'lost'],
            default => [],
        };
        if (! in_array($nextStage, $allowed, true)) {
            throw new DomainException("Opportunity cannot transition from {$opportunity->stage} to {$nextStage}.");
        }
        if ($nextStage === 'lost' && blank($lostReason)) {
            throw new DomainException('A lost opportunity requires a reason.');
        }

        $opportunity->update(['stage' => $nextStage, 'lost_reason' => $nextStage === 'lost' ? $lostReason : null]);

        return $opportunity->fresh();
    }

    public function attachProposal(Opportunity $opportunity, Proposal $proposal): Opportunity
    {
        if (! in_array($opportunity->stage, ['inquiry', 'qualified', 'proposal'], true)) {
            throw new DomainException('A closed opportunity cannot receive a proposal.');
        }
        $opportunity->update(['proposal_id' => $proposal->id, 'stage' => 'proposal', 'value_minor' => $proposal->total_minor, 'currency' => $proposal->currency]);

        return $opportunity->fresh('proposal');
    }
}
