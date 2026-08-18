<?php

namespace App\Integrations\Payments\MercadoPago;

interface MercadoPagoTransport
{
    /** @param array<string, mixed> $payload @param array<string, string> $headers @return array<string, mixed> */
    public function request(string $method, string $path, array $payload = [], array $headers = []): array;
}
