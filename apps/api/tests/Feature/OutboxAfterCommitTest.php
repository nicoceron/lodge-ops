<?php

namespace Tests\Feature;

use App\Jobs\PublishOutboxMessage;
use App\Models\Outbox;
use App\Models\Reservation;
use App\Services\Automation\OutboxRecorder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class OutboxAfterCommitTest extends TestCase
{
    use CreatesTenant, DatabaseMigrations;

    public function test_rolled_back_domain_work_neither_persists_nor_dispatches_an_event(): void
    {
        [, $property] = $this->tenantEnvironment(authenticate: false);
        $reservation = Reservation::factory()->create(['property_id' => $property->id]);
        Queue::fake();

        try {
            DB::transaction(function () use ($reservation): void {
                app(OutboxRecorder::class)->record(
                    'reservation',
                    $reservation->id,
                    'reservation.confirmed',
                    ['reservation_id' => $reservation->id],
                );

                throw new RuntimeException('Force rollback.');
            });
        } catch (RuntimeException) {
            // Expected rollback.
        }

        $this->assertSame(0, Outbox::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_committed_domain_work_is_claimed_and_dispatched_after_commit(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(authenticate: false);
        $reservation = Reservation::factory()->create(['property_id' => $property->id]);
        Queue::fake();

        DB::transaction(function () use ($reservation): void {
            app(OutboxRecorder::class)->record(
                'reservation',
                $reservation->id,
                'reservation.confirmed',
                ['reservation_id' => $reservation->id],
            );

            Queue::assertNothingPushed();
        });

        Queue::assertPushed(PublishOutboxMessage::class, function (PublishOutboxMessage $job) use ($tenant): bool {
            return $job->tenantId === $tenant->id;
        });
        $message = Outbox::query()->firstOrFail();
        $this->assertNotNull($message->claim_token);
        $this->assertNotNull($message->claimed_at);
        $this->assertNull($message->published_at);
    }
}
