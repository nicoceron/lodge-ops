<?php

namespace App\Exceptions;

use App\Enums\DirectBookingErrorCode;
use RuntimeException;

class DirectBookingContractException extends RuntimeException
{
    public function __construct(
        public readonly DirectBookingErrorCode $errorCode,
        string $message,
        public readonly int $httpStatus = 409,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}
