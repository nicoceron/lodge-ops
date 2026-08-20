<?php

namespace App\Services\Integrations;

use App\Models\IntegrationConnection;
use App\Models\IntegrationDeadLetter;
use App\Models\IntegrationReconciliation;
use Illuminate\Support\Facades\DB;

final class IntegrationReconciliationService
{
    public function resolve(IntegrationReconciliation $reconciliation, ?int $actorId, string $resolution): IntegrationReconciliation
    {
        $resolution = app(IntegrationOperatorInputGuard::class)->admit($resolution, 'resolution');

        return DB::transaction(function () use ($reconciliation, $actorId, $resolution): IntegrationReconciliation {
            $reconciliation->update([
                'status' => 'resolved',
                'resolved_by' => $actorId,
                'resolved_at' => now(),
                'resolution' => $resolution,
            ]);
            IntegrationOperationRecorder::record(
                $reconciliation->connection()->firstOrFail(),
                'reconciliation_resolved',
                $actorId,
                $resolution,
                ['reconciliation_id' => $reconciliation->id],
            );

            return $reconciliation->fresh();
        }, 3);
    }

    /** @return array{open_dead_letters_scanned:int,reconciliations:int} */
    public function reconcile(IntegrationConnection $connection, ?int $actorId, string $reason): array
    {
        $reason = app(IntegrationOperatorInputGuard::class)->admit($reason, 'reason');

        return DB::transaction(function () use ($connection, $actorId, $reason): array {
            $count = 0;
            foreach (IntegrationDeadLetter::query()->where('integration_connection_id', $connection->id)->where('status', 'open')->get() as $letter) {
                IntegrationReconciliation::query()->firstOrCreate([
                    'integration_connection_id' => $connection->id,
                    'kind' => 'dead_letter',
                    'external_key' => $letter->id,
                    'status' => 'open',
                ], [
                    'property_id' => $letter->property_id,
                    'owner_id' => $actorId,
                    'reason_code' => $letter->reason_code,
                    'safe_facts' => ['dead_letter_id' => $letter->id],
                ]);
                $count++;
            }
            IntegrationOperationRecorder::record($connection, 'reconciled', $actorId, $reason, ['open_dead_letters_scanned' => $count]);

            return [
                'open_dead_letters_scanned' => $count,
                'reconciliations' => IntegrationReconciliation::query()->where('integration_connection_id', $connection->id)->where('status', 'open')->count(),
            ];
        }, 3);
    }
}
