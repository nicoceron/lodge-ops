<?php

namespace App\Services\Integrations;

use App\Models\IntegrationConnection;
use App\Models\IntegrationDeadLetter;
use App\Models\IntegrationReconciliation;
use Illuminate\Support\Facades\DB;

final class IntegrationReconciliationService
{
    /** @return array{open_dead_letters_scanned:int,reconciliations:int} */
    public function reconcile(IntegrationConnection $connection, ?int $actorId, string $reason): array
    {
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
