<?php

namespace App\Data\Integrations;

final readonly class IntegrationPage
{
    /** @param list<array<string,mixed>> $items @param array<string,mixed>|null $nextCheckpoint */
    public function __construct(
        public array $items,
        public ?array $nextCheckpoint,
        public bool $hasMore,
    ) {}
}
