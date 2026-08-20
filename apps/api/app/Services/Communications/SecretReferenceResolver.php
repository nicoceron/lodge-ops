<?php

namespace App\Services\Communications;

use DomainException;

final class SecretReferenceResolver
{
    public function resolve(string $reference): string
    {
        if (! str_starts_with($reference, 'env:')) {
            throw new DomainException('Unsupported communication secret reference scheme.');
        }

        $name = substr($reference, 4);
        if (! preg_match('/^[A-Z][A-Z0-9_]{2,127}$/', $name)) {
            throw new DomainException('Invalid communication secret reference.');
        }

        $value = getenv($name);
        if (! is_string($value) || trim($value) === '') {
            throw new DomainException('The referenced communication secret is unavailable.');
        }

        return trim($value);
    }
}
