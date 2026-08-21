<?php

namespace App\Data\Integrations;

final readonly class IntegrationHealthResult
{
    public function __construct(public bool $healthy, public int $latencyMs, public ?int $lagSeconds = null, public ?string $safeMessage = null) {}
}
