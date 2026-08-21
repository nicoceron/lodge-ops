<?php

namespace App\Contracts\DirectBooking;

use App\Data\DirectBooking\BotVerificationResult;

interface BotVerifier
{
    public function verify(string $responseToken, ?string $remoteIp, string $expectedAction, string $idempotencyKey): BotVerificationResult;
}
