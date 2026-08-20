<?php

namespace App\Contracts\Integrations;

use App\Data\Integrations\IntegrationHealthResult;
use App\Data\Integrations\IntegrationItemResult;
use App\Data\Integrations\IntegrationPage;
use App\Data\Integrations\IntegrationServiceIdentity;
use App\Models\IntegrationConnection;

interface OutboundWebhookPort
{
    public function test(IntegrationConnection $connection): IntegrationHealthResult;

    /** Source keys must identify committed immutable Inn outbox events. @param array<string,mixed>|null $checkpoint */
    public function sourcePage(IntegrationConnection $connection, ?array $checkpoint): IntegrationPage;

    public function deliver(IntegrationConnection $connection, string $outboxKey, IntegrationServiceIdentity $identity, string $idempotencyKey): IntegrationItemResult;
}
