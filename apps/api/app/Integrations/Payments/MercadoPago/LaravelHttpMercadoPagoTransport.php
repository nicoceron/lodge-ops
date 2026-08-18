<?php

namespace App\Integrations\Payments\MercadoPago;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class LaravelHttpMercadoPagoTransport implements MercadoPagoTransport
{
    public function __construct(private readonly string $accessToken) {}

    public function request(string $method, string $path, array $payload = [], array $headers = []): array
    {
        try {
            $pending = Http::baseUrl('https://api.mercadopago.com')
                ->acceptJson()
                ->asJson()
                ->withToken($this->accessToken)
                ->withHeaders($headers)
                ->connectTimeout(5)
                ->timeout(15);
            $response = match (strtoupper($method)) {
                'GET' => $pending->get($path, $payload),
                'POST' => $pending->post($path, $payload),
                default => throw new RuntimeException('Unsupported Mercado Pago HTTP method.'),
            };
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Mercado Pago could not be reached; the result is ambiguous.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Mercado Pago returned HTTP '.$response->status().'.');
        }
        $decoded = $response->json();
        if (! is_array($decoded)) {
            throw new RuntimeException('Mercado Pago returned malformed JSON.');
        }

        return $decoded;
    }
}
