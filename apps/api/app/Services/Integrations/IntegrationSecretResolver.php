<?php

namespace App\Services\Integrations;

use App\Exceptions\IntegrationConnectionException;
use Illuminate\Support\Env;
use JsonException;

class IntegrationSecretResolver
{
    /** @return array<string, mixed> */
    public function resolve(?string $reference): array
    {
        if ($reference === null || $reference === '') {
            throw new IntegrationConnectionException('Add a secret reference before testing this connection.');
        }

        if (preg_match('/^env:\/\/([A-Z][A-Z0-9_]*)$/', $reference, $matches) !== 1) {
            throw new IntegrationConnectionException('This runtime can resolve env:// references. Configure a production secret-manager resolver before using vault or cloud references.');
        }

        $value = Env::get($matches[1]);
        if (! is_string($value) || trim($value) === '') {
            throw new IntegrationConnectionException("The referenced environment secret {$matches[1]} is not configured.");
        }

        try {
            $secret = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new IntegrationConnectionException('The integration secret must be a valid JSON object.');
        }

        if (! is_array($secret) || array_is_list($secret)) {
            throw new IntegrationConnectionException('The integration secret must be a JSON object.');
        }

        return $secret;
    }
}
