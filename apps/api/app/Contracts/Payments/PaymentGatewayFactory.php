<?php

namespace App\Contracts\Payments;

use App\Models\IntegrationConnection;

interface PaymentGatewayFactory
{
    public function for(IntegrationConnection $connection): PaymentGateway;
}
