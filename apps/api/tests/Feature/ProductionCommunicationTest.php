<?php

namespace Tests\Feature;

use App\Jobs\ProcessCommunicationDeliveryEvent;
use App\Models\Communication;
use App\Models\CommunicationDeliveryEvent;
use App\Models\CommunicationProviderConnection;
use App\Models\CommunicationSuppression;
use App\Models\DeliveryAttempt;
use App\Models\Guest;
use App\Services\CommunicationDeliveryService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class ProductionCommunicationTest extends TestCase
{
    use CreatesTenant, DatabaseMigrations;

    private string $endpointKey = 'communications_webhook_endpoint_0001';

    private string $webhookSecret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->webhookSecret = 'whsec_'.base64_encode('test-webhook-secret-material');
        putenv('COMMUNICATION_TEST_API_KEY=re_test_value');
        putenv('COMMUNICATION_TEST_WEBHOOK_SECRET='.$this->webhookSecret);
    }

    protected function tearDown(): void
    {
        putenv('COMMUNICATION_TEST_API_KEY');
        putenv('COMMUNICATION_TEST_WEBHOOK_SECRET');
        parent::tearDown();
    }

    public function test_resend_acceptance_is_not_delivery_and_authenticated_event_is_delivery_truth(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(authenticate: false);
        $connection = $this->connection($property->id);
        $guest = Guest::factory()->create(['email' => 'delivered@example.com']);
        $communication = Communication::query()->create([
            'property_id' => $property->id,
            'guest_id' => $guest->id,
            'channel' => 'email',
            'direction' => 'outbound',
            'purpose' => 'transactional',
            'status' => 'queued',
            'subject' => 'Reservation confirmed',
            'body' => 'Your reservation is confirmed.',
        ]);
        Http::fake(['api.resend.com/emails' => Http::response(['id' => 'email_123'], 200)]);

        app(CommunicationDeliveryService::class)->deliver($communication);

        $communication->refresh();
        $this->assertSame('provider_accepted', $communication->status);
        $this->assertNotNull($communication->accepted_at);
        $this->assertNull($communication->delivered_at);
        Http::assertSent(fn ($request): bool => $request->header('Idempotency-Key')[0] === 'communication:'.$communication->id
            && $request['from'] === 'Rincon Grande <reservations@mail.example.com>');

        Queue::fake();
        app(TenantContext::class)->clear();
        $raw = json_encode([
            'type' => 'email.delivered',
            'created_at' => '2026-08-20T15:00:00Z',
            'data' => ['email_id' => 'email_123', 'to' => ['delivered@example.com']],
        ], JSON_THROW_ON_ERROR);
        $this->call('POST', '/api/v1/communication-webhooks/'.$this->endpointKey, [], [], [], $this->headers($raw, 'evt_delivered_1'), $raw)
            ->assertAccepted()->assertExactJson(['accepted' => true]);

        $event = CommunicationDeliveryEvent::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($connection->id, $event->communication_provider_connection_id);
        $this->assertSame(hash('sha256', $raw), $event->raw_body_checksum);
        $this->assertArrayNotHasKey('to', $event->normalized_payload);
        (new ProcessCommunicationDeliveryEvent($tenant->id, $event->id))->handle(app(TenantContext::class));

        $this->assertDatabaseHas('communications', ['id' => $communication->id, 'status' => 'delivered']);
        $this->assertNotNull(Communication::withoutGlobalScopes()->findOrFail($communication->id)->delivered_at);
    }

    public function test_invalid_missing_stale_and_malformed_signatures_are_generic_and_persist_nothing(): void
    {
        [, $property] = $this->tenantEnvironment(authenticate: false);
        $this->connection($property->id);
        app(TenantContext::class)->clear();
        $raw = '{"type":"email.sent","data":{"email_id":"email_1"}}';

        $this->postJson('/api/v1/communication-webhooks/'.$this->endpointKey, json_decode($raw, true))->assertUnauthorized()
            ->assertExactJson(['message' => 'Invalid provider notification.']);
        $invalid = $this->headers($raw, 'evt_invalid');
        $invalid['HTTP_SVIX_SIGNATURE'] = 'v1,invalid';
        $this->call('POST', '/api/v1/communication-webhooks/'.$this->endpointKey, [], [], [], $invalid, $raw)->assertUnauthorized();
        $stale = $this->headers($raw, 'evt_stale', time() - 600);
        $this->call('POST', '/api/v1/communication-webhooks/'.$this->endpointKey, [], [], [], $stale, $raw)->assertUnauthorized();
        $malformed = 'not-json';
        $this->call('POST', '/api/v1/communication-webhooks/'.$this->endpointKey, [], [], [], $this->headers($malformed, 'evt_bad_json'), $malformed)->assertUnauthorized();

        $this->assertDatabaseCount('communication_delivery_events', 0);
    }

    public function test_duplicate_and_reordered_events_are_idempotent_and_complaint_suppresses_later_sends(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(authenticate: false);
        $this->connection($property->id);
        $guest = Guest::factory()->create(['email' => 'complaint@example.com']);
        $communication = Communication::query()->create([
            'property_id' => $property->id, 'guest_id' => $guest->id, 'channel' => 'email',
            'direction' => 'outbound', 'purpose' => 'transactional', 'status' => 'provider_accepted',
            'subject' => 'Test', 'body' => 'Test', 'accepted_at' => now(),
        ]);
        DeliveryAttempt::query()->create([
            'communication_id' => $communication->id,
            'communication_provider_connection_id' => CommunicationProviderConnection::query()->firstOrFail()->id,
            'provider' => 'resend', 'provider_account_id' => 'account_1', 'status' => 'provider_accepted',
            'kind' => 'initial', 'idempotency_key' => 'communication:'.$communication->id,
            'provider_message_id' => 'email_complaint', 'attempt' => 1, 'attempted_at' => now(), 'accepted_at' => now(),
        ]);
        Queue::fake();
        app(TenantContext::class)->clear();

        $delivered = $this->event('email.delivered', 'email_complaint', ['complaint@example.com']);
        $this->call('POST', '/api/v1/communication-webhooks/'.$this->endpointKey, [], [], [], $this->headers($delivered, 'evt_same'), $delivered)->assertAccepted();
        $this->call('POST', '/api/v1/communication-webhooks/'.$this->endpointKey, [], [], [], $this->headers($delivered, 'evt_same'), $delivered)->assertAccepted();
        $first = CommunicationDeliveryEvent::withoutGlobalScopes()->firstOrFail();
        (new ProcessCommunicationDeliveryEvent($tenant->id, $first->id))->handle(app(TenantContext::class));

        $complained = $this->event('email.complained', 'email_complaint', ['complaint@example.com']);
        $this->call('POST', '/api/v1/communication-webhooks/'.$this->endpointKey, [], [], [], $this->headers($complained, 'evt_complaint'), $complained)->assertAccepted();
        $second = CommunicationDeliveryEvent::withoutGlobalScopes()->where('provider_event_id', 'evt_complaint')->firstOrFail();
        (new ProcessCommunicationDeliveryEvent($tenant->id, $second->id))->handle(app(TenantContext::class));

        $sent = $this->event('email.sent', 'email_complaint', ['complaint@example.com']);
        $this->call('POST', '/api/v1/communication-webhooks/'.$this->endpointKey, [], [], [], $this->headers($sent, 'evt_late_sent'), $sent)->assertAccepted();
        $third = CommunicationDeliveryEvent::withoutGlobalScopes()->where('provider_event_id', 'evt_late_sent')->firstOrFail();
        (new ProcessCommunicationDeliveryEvent($tenant->id, $third->id))->handle(app(TenantContext::class));

        $this->assertDatabaseCount('communication_delivery_events', 3);
        $this->assertSame('complained', Communication::withoutGlobalScopes()->findOrFail($communication->id)->status);
        $this->assertDatabaseHas('communication_suppressions', [
            'recipient_hash' => hash('sha256', 'complaint@example.com'), 'reason' => 'complaint', 'source' => 'provider_event',
        ]);
        $this->assertSame(1, CommunicationSuppression::withoutGlobalScopes()->count());
    }

    private function connection(string $propertyId): CommunicationProviderConnection
    {
        return CommunicationProviderConnection::query()->create([
            'property_id' => $propertyId,
            'provider' => 'resend',
            'account_id' => 'account_1',
            'endpoint_key_hash' => hash('sha256', $this->endpointKey),
            'secret_ref' => 'env:COMMUNICATION_TEST_API_KEY',
            'webhook_secret_refs' => ['env:COMMUNICATION_TEST_WEBHOOK_SECRET'],
            'from_email' => 'reservations@mail.example.com',
            'from_name' => 'Rincon Grande',
            'reply_to_email' => 'frontdesk@example.com',
            'allowed_sender_domains' => ['mail.example.com'],
            'is_enabled' => true,
            'verified_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    private function headers(string $raw, string $id, ?int $timestamp = null): array
    {
        $timestamp ??= time();
        $key = base64_decode(substr($this->webhookSecret, 6), true);
        $signature = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$raw, $key, true));

        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SVIX_ID' => $id,
            'HTTP_SVIX_TIMESTAMP' => (string) $timestamp,
            'HTTP_SVIX_SIGNATURE' => 'v1,'.$signature,
        ];
    }

    /** @param list<string> $recipients */
    private function event(string $type, string $messageId, array $recipients): string
    {
        return json_encode([
            'type' => $type,
            'created_at' => '2026-08-20T15:00:00Z',
            'data' => ['email_id' => $messageId, 'to' => $recipients],
        ], JSON_THROW_ON_ERROR);
    }
}
