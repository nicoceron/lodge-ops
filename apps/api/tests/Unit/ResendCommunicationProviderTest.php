<?php

namespace Tests\Unit;

use App\Data\CommunicationProviderRequest;
use App\Exceptions\CommunicationProviderException;
use App\Services\Communications\ResendCommunicationProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResendCommunicationProviderTest extends TestCase
{
    public function test_acceptance_returns_provider_message_identity_and_sends_stable_idempotency_key(): void
    {
        Http::fake(['api.resend.com/emails' => Http::response(['id' => 'email_fixture'], 200)]);

        $result = app(ResendCommunicationProvider::class)->send($this->request());

        $this->assertSame('email_fixture', $result->providerMessageId);
        Http::assertSent(fn ($request): bool => $request->header('Idempotency-Key')[0] === 'communication:test-1');
    }

    #[DataProvider('failureFixtures')]
    public function test_provider_failures_are_safely_classified(int $status, string $code, bool $retryable): void
    {
        Http::fake(['api.resend.com/emails' => Http::response(['message' => 'safe provider message'], $status)]);

        try {
            app(ResendCommunicationProvider::class)->send($this->request());
            $this->fail('Expected provider exception.');
        } catch (CommunicationProviderException $exception) {
            $this->assertSame($code, $exception->safeCode);
            $this->assertSame($retryable, $exception->retryable);
            $this->assertFalse($exception->outcomeUncertain);
            $this->assertStringNotContainsString('re_test', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{int,string,bool}> */
    public static function failureFixtures(): iterable
    {
        yield 'rate limit' => [429, 'rate_limited', true];
        yield 'server failure' => [503, 'provider_unavailable', true];
        yield 'idempotency conflict' => [409, 'idempotency_conflict', false];
        yield 'rejected request' => [422, 'provider_rejected', false];
    }

    public function test_network_timeout_is_retryable_but_outcome_uncertain(): void
    {
        Http::fake(['api.resend.com/emails' => Factory::failedConnection('socket timeout')]);

        try {
            app(ResendCommunicationProvider::class)->send($this->request());
            $this->fail('Expected provider exception.');
        } catch (CommunicationProviderException $exception) {
            $this->assertSame('network_timeout', $exception->safeCode);
            $this->assertTrue($exception->retryable);
            $this->assertTrue($exception->outcomeUncertain);
        }
    }

    private function request(): CommunicationProviderRequest
    {
        return new CommunicationProviderRequest(
            'communication:test-1', 're_test', 'Inn <mail@example.com>', 'reply@example.com',
            'guest@example.com', 'Test', 'Plain', '<p>Plain</p>',
        );
    }
}
