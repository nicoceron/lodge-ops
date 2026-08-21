<?php

namespace App\Integrations\Payments\MercadoPago;

use RuntimeException;

final class MercadoPagoTransportException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly ?string $providerCode,
        public readonly ?int $retryAfterSeconds,
        public readonly ?string $providerResourceId = null,
    ) {
        parent::__construct('Mercado Pago returned HTTP '.$httpStatus
            .($providerCode === null ? '.' : ' ('.$providerCode.').')
            .($retryAfterSeconds === null ? '' : ' Retry after '.$retryAfterSeconds.' seconds.'));
    }
}
