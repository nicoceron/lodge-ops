<?php

namespace App\Services\Communications;

use App\Contracts\CommunicationProvider;
use App\Data\CommunicationProviderRequest;
use App\Data\CommunicationProviderResult;
use App\Exceptions\CommunicationProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class ResendCommunicationProvider implements CommunicationProvider
{
    public function name(): string
    {
        return 'resend';
    }

    public function send(CommunicationProviderRequest $request): CommunicationProviderResult
    {
        try {
            $response = Http::withToken($request->apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('communications.provider.timeout_seconds', 20))
                ->withHeaders(['Idempotency-Key' => $request->idempotencyKey])
                ->post('https://api.resend.com/emails', array_filter([
                    'from' => $request->from,
                    'to' => [$request->recipient],
                    'reply_to' => $request->replyTo,
                    'subject' => $request->subject,
                    'text' => $request->text,
                    'html' => $request->html,
                    'attachments' => $request->attachments ?: null,
                ], static fn (mixed $value): bool => $value !== null));
        } catch (ConnectionException $exception) {
            throw new CommunicationProviderException('Provider request timed out or disconnected.', 'network_timeout', true, true);
        }

        $messageId = $response->json('id');
        if ($response->successful() && is_string($messageId) && $messageId !== '') {
            return new CommunicationProviderResult($messageId);
        }

        $providerError = is_string($response->json('name')) ? $response->json('name') : null;
        $code = match (true) {
            $response->status() === 429 => 'rate_limited',
            $response->status() >= 500 => 'provider_unavailable',
            $response->status() === 409 && $providerError === 'concurrent_idempotent_requests' => 'concurrent_idempotent_requests',
            $response->status() === 409 && $providerError === 'invalid_idempotent_request' => 'invalid_idempotent_request',
            $response->status() === 409 => 'idempotency_conflict',
            default => 'provider_rejected',
        };
        $concurrentRequest = $code === 'concurrent_idempotent_requests';
        $retryable = $response->status() === 429 || $response->status() >= 500 || $concurrentRequest;
        $safeMessage = is_string($response->json('message'))
            ? mb_substr(strip_tags($response->json('message')), 0, 500)
            : "Provider returned HTTP {$response->status()}.";

        throw new CommunicationProviderException($safeMessage, $code, $retryable, $concurrentRequest);
    }
}
