<?php

namespace App\Events;

final readonly class IntegrationSchedulerHeartbeat
{
    public function __construct(
        public string $tenantId,
        public ?string $propertyId,
        public string $occurredAt,
        public int $queuedRuns,
        public int $backlogItems,
        public int $openDeadLetters,
    ) {}
}
