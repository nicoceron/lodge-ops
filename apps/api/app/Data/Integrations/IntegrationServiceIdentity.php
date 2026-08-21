<?php

namespace App\Data\Integrations;

final readonly class IntegrationServiceIdentity
{
    /** @param list<string> $capabilities */
    public function __construct(
        public string $tenantId,
        public ?string $propertyId,
        public string $connectionId,
        public array $capabilities,
        public string $correlationId,
    ) {}

    public function allows(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
}
