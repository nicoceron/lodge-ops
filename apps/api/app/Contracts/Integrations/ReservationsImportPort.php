<?php

namespace App\Contracts\Integrations;

use App\Data\Integrations\IntegrationHealthResult;
use App\Data\Integrations\IntegrationItemResult;
use App\Data\Integrations\IntegrationPage;
use App\Data\Integrations\IntegrationServiceIdentity;
use App\Models\IntegrationConnection;

interface ReservationsImportPort
{
    public function test(IntegrationConnection $connection): IntegrationHealthResult;

    /** @param array<string,mixed>|null $checkpoint */
    public function fetchPage(IntegrationConnection $connection, ?array $checkpoint): IntegrationPage;

    public function importReservation(IntegrationConnection $connection, string $externalKey, IntegrationServiceIdentity $identity, string $idempotencyKey): IntegrationItemResult;
}
