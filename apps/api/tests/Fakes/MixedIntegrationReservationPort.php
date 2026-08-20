<?php

namespace Tests\Fakes;

use App\Contracts\Integrations\ReservationsImportPort;
use App\Data\Integrations\IntegrationHealthResult;
use App\Data\Integrations\IntegrationItemResult;
use App\Data\Integrations\IntegrationPage;
use App\Data\Integrations\IntegrationServiceIdentity;
use App\Exceptions\Integrations\PoisonIntegrationException;
use App\Models\IntegrationConnection;
use App\Models\IntegrationSyncRunItem;
use DomainException;

/** Browser/contract-test simulator only. It is never registered outside APP_ENV=testing. */
final class MixedIntegrationReservationPort implements ReservationsImportPort
{
    public function test(IntegrationConnection $connection): IntegrationHealthResult
    {
        return new IntegrationHealthResult(true, 8, 0, 'Test-only contract simulator is reachable.');
    }

    public function fetchPage(IntegrationConnection $connection, ?array $checkpoint): IntegrationPage
    {
        return new IntegrationPage([
            ['external_key' => 'uat-good', 'checksum' => hash('sha256', 'uat-good'), 'safe_snapshot' => ['kind' => 'contract_fact']],
            ['external_key' => 'uat-poison', 'checksum' => hash('sha256', 'uat-poison'), 'safe_snapshot' => ['kind' => 'contract_fact']],
        ], ['after' => 'uat-page-1'], false);
    }

    public function importReservation(IntegrationConnection $connection, string $externalKey, IntegrationServiceIdentity $identity, string $idempotencyKey): IntegrationItemResult
    {
        if (! $identity->allows('reservations.import') || $identity->propertyId !== $connection->property_id) {
            throw new DomainException('Test service identity exceeded its grant.');
        }
        $attempt = IntegrationSyncRunItem::query()->where('idempotency_key', $idempotencyKey)->value('attempt');
        if ($externalKey === 'uat-poison' && $attempt === 1) {
            throw new PoisonIntegrationException('Test-only unmapped reservation fact.');
        }

        return new IntegrationItemResult('test-command:'.$externalKey, 200, 7, hash('sha256', $idempotencyKey), hash('sha256', $externalKey));
    }
}
