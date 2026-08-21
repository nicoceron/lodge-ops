<?php

namespace App\Data\Payments;

use Carbon\CarbonImmutable;

final readonly class VerifiedProviderEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $deliveryId,
        public ?string $topic,
        public ?string $type,
        public ?string $action,
        public string $resourceId,
        public array $payload,
        public ?CarbonImmutable $providerCreatedAt = null,
        public ?string $applicationId = null,
        public ?string $environment = null,
        public ?string $providerAccount = null,
    ) {}
}
