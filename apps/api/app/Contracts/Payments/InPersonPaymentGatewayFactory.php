<?php

namespace App\Contracts\Payments;

use App\Models\IntegrationConnection;

interface InPersonPaymentGatewayFactory
{
    public function for(IntegrationConnection $connection): InPersonPaymentGateway;
}
