<?php

namespace App\Services\Integrations;

use App\Models\IntegrationConnection;
use RuntimeException;

final class CapabilityPortRegistry
{
    /** @var array<string, object> */
    private array $ports = [];

    public function register(string $provider, string $product, string $capability, object $port): void
    {
        $this->ports[$this->key($provider, $product, $capability)] = $port;
    }

    public function for(IntegrationConnection $connection, string $capability): object
    {
        return $this->ports[$this->key($connection->provider, $connection->product, $capability)]
            ?? throw new RuntimeException("No named {$capability} port is registered for {$connection->provider}/{$connection->product}.");
    }

    private function key(string $provider, string $product, string $capability): string
    {
        return strtolower($provider.'|'.$product.'|'.$capability);
    }
}
