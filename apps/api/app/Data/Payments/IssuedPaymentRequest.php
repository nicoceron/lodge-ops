<?php

namespace App\Data\Payments;

use App\Models\PaymentRequest;

final readonly class IssuedPaymentRequest
{
    public function __construct(public PaymentRequest $request, public string $token) {}
}
