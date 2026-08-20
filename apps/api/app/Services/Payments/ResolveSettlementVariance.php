<?php

namespace App\Services\Payments;

use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\SettlementEntry;
use App\Models\SettlementVarianceAction;
use Illuminate\Support\Facades\DB;

final class ResolveSettlementVariance
{
    public function handle(SettlementEntry $entry, string $action, string $notes, int $actorId): SettlementEntry
    {
        $action = trim($action);
        $notes = trim($notes);
        if (! in_array($action, ['investigate', 'resolve'], true) || $notes === '') {
            throw new DomainException('A supported variance action and investigation notes are required.');
        }

        return DB::transaction(function () use ($entry, $action, $notes, $actorId): SettlementEntry {
            $locked = SettlementEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if ($locked->reconciliation_state->value !== 'variance') {
                throw new DomainException('Only a settlement variance may be investigated or resolved.');
            }
            SettlementVarianceAction::query()->create([
                'settlement_entry_id' => $locked->id,
                'actor_id' => $actorId,
                'action' => $action,
                'notes' => $notes,
                'acted_at' => now(),
            ]);
            $locked->update($action === 'investigate' ? [
                'investigated_by' => $actorId,
                'investigated_at' => now(),
            ] : [
                'reconciliation_state' => 'ignored_with_reason',
                'resolution_reason' => $notes,
                'resolved_by' => $actorId,
                'resolved_at' => now(),
            ]);

            return $locked->fresh('varianceActions');
        }, 3);
    }
}
