<?php

namespace Tests\Feature;

use App\Jobs\PublishOutboxMessage;
use App\Models\Outbox;
use App\Models\Reservation;
use App\Services\Automation\OutboxBatchPublisher;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class OutboxBatchPublisherTest extends TestCase
{
    use CreatesTenant, DatabaseMigrations;

    public function test_batch_claims_are_idempotent_and_stale_claims_can_be_recovered(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(authenticate: false);
        $reservation = Reservation::factory()->create(['property_id' => $property->id]);
        $first = $this->outbox($reservation, 'reservation.confirmed');
        $second = $this->outbox($reservation, 'reservation.status_changed');
        Queue::fake();

        $publisher = app(OutboxBatchPublisher::class);
        $this->assertSame(2, $publisher->publish(100));
        $this->assertSame(0, $publisher->publish(100));
        Queue::assertPushed(PublishOutboxMessage::class, 2);

        $first->refresh()->forceFill(['claimed_at' => now()->subMinutes(6)])->save();

        $this->assertSame(1, $publisher->publish(100));
        Queue::assertPushed(PublishOutboxMessage::class, 3);
        $this->assertNotSame($first->claim_token, $first->refresh()->claim_token);
        $this->assertSame($tenant->id, $first->tenant_id);
        $this->assertNotNull($second->refresh()->claim_token);
    }

    public function test_failed_job_releases_its_claim_for_a_later_batch_and_keeps_the_error(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(authenticate: false);
        $reservation = Reservation::factory()->create(['property_id' => $property->id]);
        $message = $this->outbox($reservation, 'reservation.confirmed');
        Queue::fake();
        app(OutboxBatchPublisher::class)->publishOne($tenant->id, $message->id);
        $message->refresh();

        $job = new PublishOutboxMessage($tenant->id, $message->id, $message->claim_token);
        $job->failed(new \RuntimeException('Worker exhausted retries.'));

        $message->refresh();
        $this->assertNull($message->claim_token);
        $this->assertNull($message->claimed_at);
        $this->assertSame('Worker exhausted retries.', $message->last_error);
        $this->assertNull($message->published_at);
    }

    private function outbox(Reservation $reservation, string $eventType): Outbox
    {
        return Outbox::query()->create([
            'aggregate_type' => 'reservation',
            'aggregate_id' => $reservation->id,
            'event_type' => $eventType,
            'payload' => ['reservation_id' => $reservation->id],
            'occurred_at' => now(),
            'available_at' => now(),
        ]);
    }
}
