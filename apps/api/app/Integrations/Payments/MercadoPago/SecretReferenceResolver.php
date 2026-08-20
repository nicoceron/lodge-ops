<?php

namespace App\Integrations\Payments\MercadoPago;

use RuntimeException;

final class SecretReferenceResolver
{
    public function resolve(?string $reference): string
    {
        if ($reference === null || ! str_starts_with($reference, 'env:')) {
            throw new RuntimeException('The payment connection secret reference is not configured.');
        }
        $name = substr($reference, 4);
        $value = getenv($name);
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('The referenced payment secret is unavailable.');
        }

        return $value;
    }
}
