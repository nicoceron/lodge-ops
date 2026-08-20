<?php

namespace App\Data;

use App\Contracts\CommunicationProvider;
use App\Models\CommunicationProviderConnection;

final readonly class ResolvedCommunicationProvider
{
    public function __construct(
        public CommunicationProvider $provider,
        public ?CommunicationProviderConnection $connection,
        public string $accountId,
        public string $apiKey,
        public string $from,
        public ?string $replyTo,
    ) {}
}
