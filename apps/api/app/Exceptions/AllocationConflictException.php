<?php

namespace App\Exceptions;

use RuntimeException;

class AllocationConflictException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $resourceId = null,
        public readonly ?string $conflictingId = null,
    ) {
        parent::__construct($message);
    }
}
