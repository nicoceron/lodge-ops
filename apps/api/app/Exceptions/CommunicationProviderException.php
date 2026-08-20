<?php

namespace App\Exceptions;

use RuntimeException;

class CommunicationProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $safeCode,
        public readonly bool $retryable,
        public readonly bool $outcomeUncertain = false,
    ) {
        parent::__construct($message);
    }
}
