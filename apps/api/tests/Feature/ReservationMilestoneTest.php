<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Jobs\DispatchReservationMilestoneOccurrence;
use App\Models\Deposit;
use App\Models\Outbox;
use App\Models\Reservation;
use App\Models\ReservationMilestoneOccurrence;
use App\Services\Automation\OutboxRecorder;
use App\Services\Communications\ReservationMilestoneScheduler;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class ReservationMilestoneTest extends TestCase
{
    use CreatesTenant, DatabaseMigrations;

    public function test_due_milestones_are_dispatched_once_per_reservation(): void
    {
        [, $property] = $this->tenantEnvironment(authenticate: false);
        $arrival = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Confirmed,
            'starts_at' => '2026-09-16T15:00:00Z',
            'ends_at' => '2026-09-19T11:00:00Z',
        ]);
        Deposit::query()->create([
            'reservation_id' => $arrival->id,
            'status' => 'due',
            'currency' => 'USD',
            'amount_minor' => 50000,
            'due_at' => '2026-09-09T12:00:00Z',
        ]);
        $departed = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::CheckedOut,
            'starts_at' => '2026-09-05T15:00:00Z',
            'ends_at' => '2026-09-09T11:00:00Z',
        ]);
        Queue::fake();
        app(TenantContext::class)->clear();

        $this->artisan('reservation-milestones:dispatch', ['--at' => '2026-09-10T12:00:00Z'])
            ->assertSuccessful();
        $this->assertSame(3, ReservationMilestoneOccurrence::withoutGlobalScopes()->where('state', 'claimed')->count());
        $this->artisan('reservation-milestones:dispatch', ['--at' => '2026-09-10T13:00:00Z'])
            ->assertSuccessful();
        $this->assertSame(3, ReservationMilestoneOccurrence::withoutGlobalScopes()->where('state', 'claimed')->count());
        $this->artisan('reservation-milestones:dispatch', ['--at' => '2026-09-15T16:00:00Z'])
            ->assertSuccessful();
        $this->assertSame(4, ReservationMilestoneOccurrence::withoutGlobalScopes()->where('state', 'claimed')->count());

        $jobs = Queue::pushed(DispatchReservationMilestoneOccurrence::class);
        $this->assertCount(4, $jobs);
        foreach ($jobs as $job) {
            $job->handle(app(TenantContext::class), app(OutboxRecorder::class));
        }

        $this->assertSame(4, ReservationMilestoneOccurrence::withoutGlobalScopes()->where('state', 'dispatched')->count());
        $this->assertSame(4, Outbox::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('reservation_milestone_occurrences', [
            'reservation_id' => $arrival->id,
            'key' => 'arrival_7_day',
        ]);
        $this->assertDatabaseHas('reservation_milestone_occurrences', [
            'reservation_id' => $arrival->id,
            'key' => 'arrival_1_day',
        ]);
        $this->assertDatabaseHas('reservation_milestone_occurrences', [
            'reservation_id' => $departed->id,
            'key' => 'post_checkout',
        ]);
        $this->assertDatabaseHas('outbox', ['event_type' => 'deposit.overdue']);
        $this->assertFalse(app(TenantContext::class)->check());
    }

    public function test_two_scheduler_nodes_claim_once_and_amendment_after_claim_supersedes_without_dispatch(): void
    {
        [, $property] = $this->tenantEnvironment(authenticate: false);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id, 'status' => ReservationStatus::Confirmed,
            'starts_at' => '2026-09-16T15:00:00Z', 'ends_at' => '2026-09-19T11:00:00Z', 'revision' => 1,
        ]);
        $scheduler = app(ReservationMilestoneScheduler::class);
        $scheduler->synchronize($reservation);
        Queue::fake();

        $firstNode = $scheduler->claimDue(CarbonImmutable::parse('2026-09-20T00:00:00Z'));
        $secondNode = $scheduler->claimDue(CarbonImmutable::parse('2026-09-20T00:00:00Z'));
        $this->assertCount(2, $firstNode);
        $this->assertCount(0, $secondNode);

        Reservation::withoutEvents(fn () => $reservation->update(['revision' => 2, 'starts_at' => '2026-09-17T15:00:00Z']));
        foreach ($firstNode as $claim) {
            (new DispatchReservationMilestoneOccurrence($claim['tenant_id'], $claim['id'], $claim['claim_token']))
                ->handle(app(TenantContext::class), app(OutboxRecorder::class));
        }

        $this->assertDatabaseCount('outbox', 0);
        $this->assertSame(2, ReservationMilestoneOccurrence::query()->where('state', 'superseded')->count());
        $this->assertSame(2, $scheduler->synchronize($reservation->fresh()));
        $this->assertSame(2, ReservationMilestoneOccurrence::query()->where('reservation_revision', 2)->where('state', 'pending')->count());
    }

    public function test_property_local_targets_cover_spring_fall_leap_day_and_preserve_timezone_policy(): void
    {
        [, $property] = $this->tenantEnvironment(authenticate: false);
        $property->update(['timezone' => 'America/New_York']);
        $scheduler = app(ReservationMilestoneScheduler::class);
        $fixtures = [
            ['start' => '2026-03-15T06:30:00Z', 'expected_local' => '2026-03-08 03:30:00', 'expected_utc' => '2026-03-08T07:30:00+00:00'],
            ['start' => '2026-11-08T06:30:00Z', 'expected_local' => '2026-11-01 01:30:00', 'expected_utc' => '2026-11-01T06:30:00+00:00'],
            ['start' => '2028-03-07T15:00:00Z', 'expected_local' => '2028-02-29 10:00:00', 'expected_utc' => '2028-02-29T15:00:00+00:00'],
        ];

        foreach ($fixtures as $fixture) {
            $reservation = Reservation::factory()->create([
                'property_id' => $property->id, 'status' => ReservationStatus::Confirmed,
                'starts_at' => $fixture['start'], 'ends_at' => CarbonImmutable::parse($fixture['start'])->addDays(3),
            ]);
            $scheduler->synchronize($reservation);
            $occurrence = ReservationMilestoneOccurrence::query()
                ->where('reservation_id', $reservation->id)->where('key', 'arrival_7_day')->firstOrFail();
            $this->assertSame($fixture['expected_local'], $occurrence->getRawOriginal('target_local'));
            $this->assertSame($fixture['expected_utc'], $occurrence->target_at->toIso8601String());
            $this->assertSame('America/New_York', $occurrence->timezone);
            $this->assertStringContainsString('dst-shift-forward-ambiguous-standard', $occurrence->policy_version);
        }

        $first = ReservationMilestoneOccurrence::query()
            ->where('key', 'arrival_7_day')->orderBy('target_at')->firstOrFail();
        $property->update(['timezone' => 'UTC']);
        $this->assertSame('America/New_York', $first->fresh()->timezone);
        $this->assertSame('2026-03-08 03:30:00', $first->fresh()->getRawOriginal('target_local'));
    }

    public function test_stale_claim_is_recovered_with_a_new_token_and_replayed_exactly_once(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(authenticate: false);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Confirmed,
            'starts_at' => now()->addDays(6),
            'ends_at' => now()->addDays(9),
        ]);
        $scheduler = app(ReservationMilestoneScheduler::class);
        $scheduler->synchronize($reservation);
        $occurrence = ReservationMilestoneOccurrence::query()->where('key', 'arrival_7_day')->firstOrFail();
        $occurrence->forceFill([
            'state' => 'claimed',
            'claim_token' => '11111111-1111-4111-8111-111111111111',
            'claimed_at' => now()->subMinutes(11),
        ])->save();
        Queue::fake();

        $claims = $scheduler->claimDue(now()->toImmutable());

        $this->assertCount(1, $claims);
        $this->assertNotSame('11111111-1111-4111-8111-111111111111', $claims[0]['claim_token']);
        (new DispatchReservationMilestoneOccurrence($tenant->id, $occurrence->id, $claims[0]['claim_token']))
            ->handle(app(TenantContext::class), app(OutboxRecorder::class));
        (new DispatchReservationMilestoneOccurrence($tenant->id, $occurrence->id, $claims[0]['claim_token']))
            ->handle(app(TenantContext::class), app(OutboxRecorder::class));
        $this->assertSame(1, Outbox::withoutGlobalScopes()->count());
        $this->assertSame('dispatched', $occurrence->fresh()->state);
    }

    public function test_enqueue_failure_returns_claim_to_durable_pending_for_restart_replay(): void
    {
        [, $property] = $this->tenantEnvironment(authenticate: false);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Confirmed,
            'starts_at' => now()->addDays(6),
            'ends_at' => now()->addDays(9),
        ]);
        $scheduler = app(ReservationMilestoneScheduler::class);
        $scheduler->synchronize($reservation);
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));

        $scheduler->claimDue(now()->toImmutable());

        $occurrence = ReservationMilestoneOccurrence::query()->where('key', 'arrival_7_day')->firstOrFail();
        $this->assertSame('pending', $occurrence->state);
        $this->assertNull($occurrence->claim_token);
        $this->assertStringContainsString('durable replay', $occurrence->last_error);
        Bus::fake();
        $this->assertCount(1, $scheduler->claimDue(now()->toImmutable()));
        Bus::assertDispatched(DispatchReservationMilestoneOccurrence::class);
    }
}
