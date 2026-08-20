<?php

namespace Tests\Feature;

use App\Jobs\PublishOutboxMessage;
use App\Jobs\SendCommunication;
use App\Mail\CommunicationMail;
use App\Models\Communication;
use App\Models\CommunicationSuppression;
use App\Models\Guest;
use App\Models\Outbox;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\OutboxBatchPublisher;
use App\Services\CommunicationDeliveryService;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class CommunicationDeliveryTest extends TestCase
{
    use CreatesTenant, DatabaseMigrations;

    public function test_email_delivery_is_audited_and_idempotent(): void
    {
        $this->tenantEnvironment(authenticate: false);
        $guest = Guest::factory()->create(['email' => 'traveler@example.com']);
        $communication = Communication::query()->create([
            'guest_id' => $guest->id,
            'channel' => 'email',
            'direction' => 'outbound',
            'status' => 'queued',
            'subject' => 'Your lodge confirmation',
            'body' => 'Your stay is confirmed.',
        ]);
        Mail::fake();

        $delivery = app(CommunicationDeliveryService::class);
        $delivery->deliver($communication);
        $delivery->deliver($communication->fresh());

        Mail::assertSent(CommunicationMail::class, 1);
        Mail::assertSent(CommunicationMail::class, fn (CommunicationMail $mail): bool => $mail->hasTo('traveler@example.com')
            && $mail->subjectLine === 'Your lodge confirmation'
            && $mail->bodyText === 'Your stay is confirmed.');
        $this->assertDatabaseHas('communications', [
            'id' => $communication->id,
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('delivery_attempts', [
            'communication_id' => $communication->id,
            'status' => 'sent',
            'attempt' => 1,
        ]);
        $this->assertSame(1, $communication->deliveryAttempts()->count());
    }

    public function test_unconfigured_channels_fail_without_pretending_delivery(): void
    {
        $this->tenantEnvironment(authenticate: false);
        $guest = Guest::factory()->create(['phone' => '+15551234567']);
        $communication = Communication::query()->create([
            'guest_id' => $guest->id,
            'channel' => 'sms',
            'direction' => 'outbound',
            'status' => 'queued',
            'body' => 'Arrival update',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No delivery adapter is configured');

        app(CommunicationDeliveryService::class)->deliver($communication);
    }

    public function test_delivery_rechecks_suppression_added_after_queueing(): void
    {
        $this->tenantEnvironment(authenticate: false);
        $guest = Guest::factory()->create(['email' => 'late-suppression@example.com']);
        $communication = Communication::query()->create([
            'guest_id' => $guest->id,
            'channel' => 'email',
            'direction' => 'outbound',
            'status' => 'queued',
            'subject' => 'Must not send',
            'body' => 'Must not send',
        ]);
        CommunicationSuppression::query()->create([
            'channel' => 'email',
            'recipient_hash' => hash('sha256', 'late-suppression@example.com'),
            'reason' => 'unsubscribe',
        ]);
        Mail::fake();

        app(CommunicationDeliveryService::class)->deliver($communication);

        Mail::assertNothingSent();
        $this->assertDatabaseHas('communications', ['id' => $communication->id, 'status' => 'suppressed']);
        $this->assertDatabaseCount('delivery_attempts', 0);
    }

    public function test_communication_outbox_events_reach_the_delivery_adapter(): void
    {
        [$tenant] = $this->tenantEnvironment(authenticate: false);
        $guest = Guest::factory()->create(['email' => 'outbox@example.com']);
        $communication = Communication::query()->create([
            'guest_id' => $guest->id,
            'channel' => 'email',
            'direction' => 'outbound',
            'status' => 'queued',
            'subject' => 'Arrival details',
            'body' => 'Your transfer is confirmed.',
        ]);
        $outbox = Outbox::query()->create([
            'aggregate_type' => 'communication',
            'aggregate_id' => $communication->id,
            'event_type' => 'communication.queued',
            'payload' => ['communication_id' => $communication->id, 'channel' => 'email'],
            'occurred_at' => now(),
            'available_at' => now(),
        ]);
        Mail::fake();
        Queue::fake();
        app(TenantContext::class)->clear();

        app(OutboxBatchPublisher::class)->publishOne($tenant->id, $outbox->id);
        $claimed = Outbox::withoutGlobalScopes()->findOrFail($outbox->id);
        (new PublishOutboxMessage($tenant->id, $outbox->id, $claimed->claim_token))
            ->handle(app(AutomationEngine::class), app(TenantContext::class));

        Queue::assertPushed(SendCommunication::class, fn (SendCommunication $job): bool => $job->communicationId === $communication->id);
        (new SendCommunication($tenant->id, $communication->id))
            ->handle(app(CommunicationDeliveryService::class), app(TenantContext::class));

        Mail::assertSent(CommunicationMail::class, 1);
        $this->assertDatabaseHas('communications', ['id' => $communication->id, 'status' => 'sent']);
        $this->assertNotNull(Outbox::withoutGlobalScopes()->findOrFail($outbox->id)->published_at);
        $this->assertFalse(app(TenantContext::class)->check());
    }
}
