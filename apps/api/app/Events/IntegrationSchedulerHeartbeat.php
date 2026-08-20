<?php

namespace App\Events;

final readonly class IntegrationSchedulerHeartbeat
{
    public function __construct(
        public string $occurredAt,
        public int $queuedRuns,
        public int $backlogItems,
        public int $openDeadLetters,
    ) {}
}
