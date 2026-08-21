<?php

namespace App\Integrations\Payments\MercadoPago;

use App\Data\Payments\ExactJsonDecimal;
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
                'POST' => $pending->withBody($this->encodeJson($payload), 'application/json')->post($path),
                default => throw new RuntimeException('Unsupported Mercado Pago HTTP method.'),
            };
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Mercado Pago could not be reached; the result is ambiguous.', previous: $exception);
        }

        if (! $response->successful()) {
            $body = $response->json();
            $code = is_array($body) ? data_get($body, 'error', data_get($body, 'code')) : null;
            $retryAfter = $response->header('Retry-After');
            $resourceId = is_array($body) ? data_get($body, 'data.order_id', data_get($body, 'order_id')) : null;
            throw new MercadoPagoTransportException(
                $response->status(),
                is_string($code) && preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $code) === 1 ? $code : null,
                $retryAfter !== '' && ctype_digit($retryAfter) ? (int) $retryAfter : null,
                is_string($resourceId) && preg_match('/^ORD[A-Za-z0-9_-]{8,160}$/', $resourceId) === 1 ? $resourceId : null,
            );
        }
        $decoded = $response->json();
        if (! is_array($decoded)) {
            throw new RuntimeException('Mercado Pago returned malformed JSON.');
        }

        return $decoded;
    }

    private function encodeJson(mixed $value): string
    {
        if ($value instanceof ExactJsonDecimal) {
            return $value->value;
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(',', array_map($this->encodeJson(...), $value)).']';
            }

            return '{'.implode(',', collect($value)->map(
                fn (mixed $item, string|int $key): string => json_encode((string) $key, JSON_THROW_ON_ERROR).':'.$this->encodeJson($item),
            )->values()->all()).'}';
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
