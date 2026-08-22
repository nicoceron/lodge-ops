<?php

namespace App\Http\Clients;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class DirectBookingUiClient
{
    public function property(Request $browserRequest, string $propertySlug, string $locale): Response
    {
        return $this->client($browserRequest)->get($this->path($propertySlug), ['locale' => $locale]);
    }

    public function policy(Request $browserRequest, string $propertySlug, string $kind, string $locale): Response
    {
        return $this->client($browserRequest)->get($this->path($propertySlug).'/policies/'.$kind, ['locale' => $locale]);
    }

    /** @param array<string, mixed> $facts */
    public function availability(Request $browserRequest, string $propertySlug, array $facts): Response
    {
        return $this->client($browserRequest)->post($this->path($propertySlug).'/availability', $facts);
    }

    /** @param array<string, mixed> $facts */
    public function begin(Request $browserRequest, string $propertySlug, array $facts, string $idempotencyKey): Response
    {
        return $this->mutation($browserRequest, $idempotencyKey)->post($this->path($propertySlug).'/orders', $facts);
    }

    /** @param array<string, mixed> $facts */
    public function quote(Request $browserRequest, string $propertySlug, string $reference, string $token, array $facts, string $idempotencyKey): Response
    {
        return $this->mutation($browserRequest, $idempotencyKey, $token)
            ->post($this->orderPath($propertySlug, $reference).'/quote', $facts);
    }

    /** @param array<string, mixed> $facts */
    public function hold(Request $browserRequest, string $propertySlug, string $reference, string $token, array $facts, string $idempotencyKey): Response
    {
        return $this->mutation($browserRequest, $idempotencyKey, $token)
            ->post($this->orderPath($propertySlug, $reference).'/hold', $facts);
    }

    public function status(Request $browserRequest, string $propertySlug, string $reference, string $token): Response
    {
        $query = [];
        if ((bool) config('direct-booking-ui.allow_fixture_controls')) {
            $fixtureState = $browserRequest->query('fixture_state');
            $fixtureError = $browserRequest->query('fixture_error');
            if (is_string($fixtureState) && preg_match('/^[a-z_]{3,40}$/', $fixtureState)) {
                $query['fixture_state'] = $fixtureState;
            }
            if (is_string($fixtureError) && preg_match('/^[a-z_]{3,40}$/', $fixtureError)) {
                $query['fixture_error'] = $fixtureError;
            }
        }

        return $this->client($browserRequest, $token)->get($this->orderPath($propertySlug, $reference), $query);
    }

    /** @param array<string, mixed> $facts */
    public function checkout(Request $browserRequest, string $propertySlug, string $reference, string $token, array $facts, string $idempotencyKey): Response
    {
        return $this->mutation($browserRequest, $idempotencyKey, $token)
            ->post($this->orderPath($propertySlug, $reference).'/checkout', $facts);
    }

    /** @param array<string, mixed> $facts */
    public function retryPayment(Request $browserRequest, string $propertySlug, string $reference, string $token, array $facts, string $idempotencyKey): Response
    {
        return $this->mutation($browserRequest, $idempotencyKey, $token)
            ->post($this->orderPath($propertySlug, $reference).'/payments/retry', $facts);
    }

    public function evidence(Request $browserRequest, string $propertySlug, string $reference, string $token, int $expectedVersion, UploadedFile $file, string $idempotencyKey): Response
    {
        return $this->mutation($browserRequest, $idempotencyKey, $token, multipart: true)
            ->attach('evidence', $file->get(), $file->getClientOriginalName(), ['Content-Type' => $file->getMimeType() ?: 'application/octet-stream'])
            ->post($this->orderPath($propertySlug, $reference).'/manual-payment-evidence', [
                'expected_state_version' => (string) $expectedVersion,
            ]);
    }

    /** @param array<string, mixed> $facts */
    public function recover(Request $browserRequest, string $propertySlug, string $reference, string $recoveryToken, array $facts, string $idempotencyKey): Response
    {
        return $this->mutation($browserRequest, $idempotencyKey, $recoveryToken)
            ->post($this->orderPath($propertySlug, $reference).'/recover', $facts);
    }

    public function confirmation(Request $browserRequest, string $propertySlug, string $reference, string $token): Response
    {
        return $this->client($browserRequest, $token)->get($this->orderPath($propertySlug, $reference).'/confirmation');
    }

    public function document(Request $browserRequest, string $propertySlug, string $reference, string $documentReference, string $token): Response
    {
        return $this->client($browserRequest, $token)
            ->get($this->orderPath($propertySlug, $reference).'/confirmation/documents/'.$documentReference);
    }

    private function mutation(Request $request, string $idempotencyKey, ?string $token = null, bool $multipart = false): PendingRequest
    {
        return $this->client($request, $token, $multipart)->withHeader('Idempotency-Key', $idempotencyKey);
    }

    private function client(Request $request, ?string $token = null, bool $multipart = false): PendingRequest
    {
        $pending = Http::acceptJson();
        if (! $multipart) {
            $pending = $pending->asJson();
        } else {
            // Guzzle supplies the multipart boundary. An inherited JSON
            // Content-Type header prevents Symfony from parsing the upload.
            $pending = $pending->withOptions(['headers' => []])->asMultipart()->acceptJson();
        }
        $pending = $pending
            ->timeout(10)
            ->connectTimeout(3)
            ->withHeader('X-Correlation-ID', (string) Str::uuid());

        return $token === null ? $pending : $pending->withToken($token);
    }

    private function path(string $propertySlug): string
    {
        return $this->baseUrl().'/direct-booking/properties/'.$propertySlug;
    }

    private function orderPath(string $propertySlug, string $reference): string
    {
        return $this->path($propertySlug).'/orders/'.$reference;
    }

    private function baseUrl(): string
    {
        $configured = trim((string) config('direct-booking-ui.api_base_url'));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return rtrim((string) config('app.url'), '/').'/api/v1';
    }
}
