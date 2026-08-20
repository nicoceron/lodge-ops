<?php

namespace App\Contracts\Integrations;

interface SecretReferenceResolver
{
    public function resolve(string $reference): string;
}
