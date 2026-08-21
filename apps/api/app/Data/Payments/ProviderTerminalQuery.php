<?php

namespace App\Data\Payments;

final readonly class ProviderTerminalQuery
{
    public function __construct(
        public ?string $storeId = null,
        public ?string $posId = null,
        public int $limit = 50,
        public int $offset = 0,
    ) {}
}
