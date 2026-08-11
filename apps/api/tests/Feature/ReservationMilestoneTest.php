<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Deposit;
use App\Models\Outbox;
use App\Models\Reservation;
use App\Models\ReservationAutomationMilestone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
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
            ->expectsOutput('Dispatched 3 new reservation milestone event(s).')
            ->assertSuccessful();
        $this->artisan('reservation-milestones:dispatch', ['--at' => '2026-09-10T13:00:00Z'])
            ->expectsOutput('Dispatched 0 new reservation milestone event(s).')
            ->assertSuccessful();

        $this->assertSame(3, ReservationAutomationMilestone::withoutGlobalScopes()->count());
        $this->assertSame(3, Outbox::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('reservation_automation_milestones', [
            'reservation_id' => $arrival->id,
            'key' => 'arrival_7_day',
        ]);
        $this->assertDatabaseHas('reservation_automation_milestones', [
            'reservation_id' => $departed->id,
            'key' => 'post_checkout',
        ]);
        $this->assertDatabaseHas('outbox', ['event_type' => 'deposit.overdue']);
        $this->assertFalse(app(TenantContext::class)->check());
    }
}
