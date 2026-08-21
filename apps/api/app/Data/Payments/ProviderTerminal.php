<?php

namespace App\Data\Payments;

final readonly class ProviderTerminal
{
    public function __construct(
        public string $id,
        public string $operatingMode,
        public ?string $storeId = null,
        public ?string $posId = null,
        public ?string $externalPosId = null,
    ) {}
}
