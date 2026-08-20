<?php

namespace App\Integrations\Secrets;

use App\Contracts\Integrations\SecretReferenceResolver;
use RuntimeException;

final class EnvironmentSecretReferenceResolver implements SecretReferenceResolver
{
    public function resolve(string $reference): string
    {
        if (! str_starts_with($reference, 'env:')) {
            throw new RuntimeException('This runtime has no resolver for the configured secret-manager reference.');
        }
        $name = substr($reference, 4);
        $value = getenv($name);
        if ($name === '' || ! is_string($value) || trim($value) === '') {
            throw new RuntimeException('The referenced integration secret is unavailable.');
        }

        return $value;
    }
}
