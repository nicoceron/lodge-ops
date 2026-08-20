<?php

namespace App\Data;

final readonly class CommunicationProviderResult
{
    public function __construct(
        public string $providerMessageId,
        public string $status = 'accepted',
    ) {}
}
