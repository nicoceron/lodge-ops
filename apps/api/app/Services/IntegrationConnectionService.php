<?php

namespace App\Services;

use App\Models\IntegrationConnection;
use DomainException;

class IntegrationConnectionService
{
    /** @param array<string, mixed> $configuration */
    public function configure(string $name, string $type, array $configuration, ?string $secretReference): IntegrationConnection
    {
        array_walk_recursive($configuration, function (mixed $value, string|int $key): void {
            if (is_string($key) && preg_match('/secret|password|credential|private.?key|access.?token|api.?key/i', $key) === 1) {
                throw new DomainException('Secrets must be stored in the external secret manager.');
            }
        });

        if ($secretReference !== null && preg_match('/^(vault|aws-sm|gcp-sm|azure-kv|secret|env):\/\/[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $secretReference) !== 1) {
            throw new DomainException('Secret references must use an approved secret-manager URI.');
        }

        return IntegrationConnection::query()->updateOrCreate(
            ['type' => $type, 'name' => $name],
            [
                'status' => $secretReference === null ? 'disconnected' : 'configured',
                'secret_reference' => $secretReference,
                'configuration' => $configuration,
                'last_error' => null,
            ],
        );
    }
}
