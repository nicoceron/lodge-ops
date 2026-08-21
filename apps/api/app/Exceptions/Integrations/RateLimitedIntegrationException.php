<?php

namespace App\Exceptions\Integrations;

class RateLimitedIntegrationException extends RetryableIntegrationException
{
    public function __construct(string $message, public readonly int $retryAfterSeconds)
    {
        parent::__construct($message);
    }
}
