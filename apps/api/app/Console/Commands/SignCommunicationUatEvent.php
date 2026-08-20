<?php

namespace App\Console\Commands;

use App\Services\Communications\SecretReferenceResolver;
use Illuminate\Console\Command;

class SignCommunicationUatEvent extends Command
{
    protected $signature = 'uat:sign-communication-event {eventId} {payloadBase64}';

    protected $description = 'Sign an explicitly test-origin communication event for the local P3-04 browser journey.';

    public function handle(SecretReferenceResolver $secrets): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Communication UAT fixtures may only be signed in local or testing environments.');

            return self::FAILURE;
        }
        $eventId = (string) $this->argument('eventId');
        if (! str_starts_with($eventId, 'test-origin-')) {
            $this->error('The event must be explicitly identified as test-origin.');

            return self::FAILURE;
        }
        $rawBody = base64_decode((string) $this->argument('payloadBase64'), true);
        if (! is_string($rawBody) || $rawBody === '') {
            $this->error('The event payload is invalid.');

            return self::FAILURE;
        }
        $secret = $secrets->resolve('env:COMMUNICATION_UAT_WEBHOOK_SECRET');
        $encoded = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
        $key = base64_decode($encoded, true);
        if (! is_string($key) || $key === '') {
            $this->error('The UAT signing key is invalid.');

            return self::FAILURE;
        }

        $timestamp = (string) time();
        $signature = base64_encode(hash_hmac('sha256', $eventId.'.'.$timestamp.'.'.$rawBody, $key, true));
        $this->line(json_encode([
            'origin' => 'deterministic_test_fixture',
            'svix_id' => $eventId,
            'svix_timestamp' => $timestamp,
            'svix_signature' => 'v1,'.$signature,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
