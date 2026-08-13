<?php

namespace App\Services\Integrations;

use App\Exceptions\IntegrationConnectionException;
use App\Models\IntegrationConnection;

class IntegrationConnectionHealthService
{
    public function __construct(private MewsConnectorClient $mews) {}

    public function test(IntegrationConnection $connection): IntegrationConnection
    {
        try {
            $remote = match ($connection->type) {
                'mews' => $this->mews->configuration($connection),
                default => throw new IntegrationConnectionException('Automated health checks are not available for this integration type.'),
            };

            $configuration = $connection->configuration ?? [];
            $configuration['remote'] = $remote;
            $connection->update([
                'status' => 'connected',
                'configuration' => $configuration,
                'last_checked_at' => now(),
                'last_error' => null,
            ]);
        } catch (IntegrationConnectionException $exception) {
            $connection->update([
                'status' => 'error',
                'last_checked_at' => now(),
                'last_error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return $connection->fresh();
    }
}
