<?php

namespace App\Contracts\Integrations;

use App\Data\Integrations\IntegrationHealthResult;
use App\Data\Integrations\IntegrationItemResult;
use App\Data\Integrations\IntegrationServiceIdentity;
use App\Models\IntegrationConnection;
use App\Models\IntegrationEvent;

interface InboundWebhookPort
{
    public function test(IntegrationConnection $connection): IntegrationHealthResult;

    public function consume(IntegrationConnection $connection, IntegrationEvent $event, IntegrationServiceIdentity $identity, string $idempotencyKey): IntegrationItemResult;
}
