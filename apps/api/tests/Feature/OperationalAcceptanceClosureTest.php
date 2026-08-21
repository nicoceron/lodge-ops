<?php

namespace Tests\Feature;

use App\Enums\AllocationStatus;
use App\Enums\MembershipRole;
use App\Enums\ReservationStatus;
use App\Enums\TaskStatus;
use App\Models\Allocation;
use App\Models\ChecklistTemplate;
use App\Models\Guest;
use App\Models\OperationalTask;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Reservation;
use App\Models\ReservationChecklistException;
use App\Models\Resource;
use App\Services\BookingQuoteService;
use App\Services\ChecklistWorkflowService;
use App\Services\Projections\CalendarProjectionService;
use App\Services\ProposalService;
use App\Services\ReservationCompanionService;
use App\Services\TaskLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class OperationalAcceptanceClosureTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_task_failure_reopen_escalation_completion_and_stale_revision_are_audited(): void
    {
        [, $property, $user] = $this->tenantEnvironment();
        $task = OperationalTask::query()->create([
            'property_id' => $property->id, 'title' => 'Inspect guide vehicle',
            'priority' => 'normal', 'status' => TaskStatus::Todo, 'due_at' => now()->subMinute(),
        ]);
        $service = app(TaskLifecycleService::class);

        $task = $service->transition($task, 'start', ['expected_revision' => 1], $user->id);
        $task = $service->transition($task, 'fail', ['expected_revision' => 2, 'reason' => 'Brake light failed.'], $user->id);
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame('Brake light failed.', $task->failure_reason);

        try {
            $service->transition($task, 'reopen', ['expected_revision' => 2], $user->id);
            $this->fail('A stale task revision was accepted.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $task = $service->transition($task, 'reopen', ['expected_revision' => 3], $user->id);
        $task = $service->transition($task, 'escalate', ['expected_revision' => 4, 'reason' => 'Departure is inside one hour.'], $user->id);
        $task = $service->transition($task, 'complete', ['expected_revision' => 5], $user->id);
        $this->assertSame(TaskStatus::Done, $task->status);
        $this->assertSame(['started', 'failed', 'reopened', 'escalated', 'completed'], $task->events->pluck('type')->all());
        $this->assertDatabaseHas('outbox', ['aggregate_id' => $task->id, 'event_type' => 'operational_task.completed']);
    }

    public function test_manual_inquiry_proposal_uses_server_quote_and_converts_exactly_once(): void
    {
        [, $property, $user] = $this->tenantEnvironment();
        $category = $this->category($property, 'room');
        $room = Resource::factory()->create(['property_id' => $property->id, 'category_id' => $category->id, 'capacity' => 3]);
        $plan = RatePlan::query()->create(['property_id' => $property->id, 'name' => 'Manual inquiry rate', 'currency' => 'USD', 'maximum_occupancy' => 3]);
        RateRule::query()->create(['rate_plan_id' => $plan->id, 'resource_category_id' => $category->id, 'amount_minor' => 12_500]);
        DB::table('rate_plans')->where('id', $plan->id)->update(['state' => 'published', 'published_at' => now()]);
        $quote = app(BookingQuoteService::class)->create([
            'property_id' => $property->id, 'rate_plan_id' => $plan->id,
            'resource_category_id' => $category->id, 'resource_id' => $room->id,
            'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addDays(2),
            'adults' => 2, 'children' => 0,
        ]);
        $guest = Guest::factory()->create();
        $service = app(ProposalService::class);
        $proposal = $service->createDraft([
            'booking_quote_id' => $quote->id, 'inquiry_source' => 'whatsapp',
            'property_id' => $property->id, 'primary_guest_id' => $guest->id,
            'starts_at' => now(), 'ends_at' => now()->addDay(), 'adults' => 99,
            'currency' => 'COP', 'tax_minor' => 999_999,
            'lines' => [['description' => 'Client supplied total', 'quantity_thousandths' => 1000, 'unit_amount_minor' => 1]],
        ], $user->id);

        $this->assertSame($quote->total_minor, $proposal->total_minor);
        $this->assertSame($quote->tax_minor, $proposal->tax_minor);
        $this->assertSame('USD', $proposal->currency);
        $this->assertSame('booking_quote', data_get($proposal->snapshot, 'pricing_source'));
        $this->assertSame('whatsapp', $proposal->inquiry_source);

        $sent = $service->send($proposal);
        $first = $service->convertToReservation($sent);
        $second = $service->convertToReservation($sent->fresh());
        $this->assertSame($first->id, $second->id);
        $this->assertSame($quote->total_minor, $first->total_minor);
        $this->assertSame('whatsapp', $first->source);
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_checklist_versions_regenerate_without_erasing_started_work_and_apply_reservation_exceptions(): void
    {
        [, $property, $user] = $this->tenantEnvironment();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id, 'status' => ReservationStatus::Confirmed,
            'starts_at' => now()->addDays(10)->startOfDay(), 'ends_at' => now()->addDays(12)->startOfDay(),
        ]);
        $template = ChecklistTemplate::query()->create([
            'property_id' => $property->id, 'name' => 'Arrival readiness', 'role' => 'operations', 'state' => 'draft',
        ]);
        $workflow = app(ChecklistWorkflowService::class);
        $v1 = $workflow->publish($template, [
            ['title' => 'Inspect room', 'due_offset_minutes' => -120],
            ['title' => 'Stage welcome kit', 'due_offset_minutes' => -60],
        ], $user->id);
        ReservationChecklistException::query()->create([
            'reservation_id' => $reservation->id, 'operation' => 'add',
            'title' => 'Prepare child bed', 'priority' => 'high', 'due_offset_minutes' => -90,
            'created_by' => $user->id,
        ]);
        $first = $workflow->generate($reservation, $v1, $user->id);
        $this->assertSame(['created' => 3, 'superseded' => 0, 'generation' => 1], $first);

        $started = OperationalTask::query()->where('title', 'Inspect room')->firstOrFail();
        app(TaskLifecycleService::class)->transition($started, 'start', ['expected_revision' => 1], $user->id);
        $v2 = $workflow->publish($template, [
            ['title' => 'Inspect room v2', 'due_offset_minutes' => -150],
            ['title' => 'Confirm transport', 'due_offset_minutes' => -30],
        ], $user->id);
        $second = $workflow->generate($reservation, $v2, $user->id);

        $this->assertSame(3, $second['created']);
        $this->assertSame(2, $second['superseded']);
        $this->assertSame(TaskStatus::InProgress, $started->fresh()->status);
        $this->assertDatabaseHas('operational_tasks', ['title' => 'Stage welcome kit', 'status' => TaskStatus::Superseded->value]);
        $this->assertDatabaseHas('operational_tasks', ['title' => 'Prepare child bed', 'generation' => 2, 'status' => TaskStatus::Todo->value]);
        $this->assertDatabaseHas('outbox', ['aggregate_id' => $reservation->id, 'event_type' => 'reservation.checklist.generated']);
    }

    public function test_ordered_companion_mutation_revalidates_occupancy_and_emits_kitchen_projection_fact(): void
    {
        [, $property, $user] = $this->tenantEnvironment();
        $lead = Guest::factory()->create();
        $first = Guest::factory()->create();
        $second = Guest::factory()->create();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id, 'primary_guest_id' => $lead->id,
            'status' => ReservationStatus::Confirmed, 'adults' => 2, 'children' => 0, 'infants' => 0,
        ]);
        $service = app(ReservationCompanionService::class);
        $updated = $service->replace($reservation, [[
            'guest_id' => $first->id, 'dietary' => 'Vegetarian', 'allergies' => 'Tree nuts',
        ]], 1, $user->id);

        $pivot = $updated->guests->firstWhere('id', $first->id)?->pivot;
        $this->assertSame(1, $pivot?->sort_order);
        $this->assertSame('Vegetarian', data_get($pivot?->operational_preferences, 'dietary'));
        $this->assertDatabaseHas('reservation_changes', ['reservation_id' => $reservation->id, 'type' => 'companions_changed']);
        $this->assertDatabaseHas('outbox', ['aggregate_id' => $reservation->id, 'event_type' => 'reservation.companions_changed']);

        $this->expectException(ValidationException::class);
        $service->replace($updated, [
            ['guest_id' => $first->id], ['guest_id' => $second->id],
        ], $updated->revision, $user->id);
    }

    public function test_guide_can_manage_only_own_availability_and_assigned_work(): void
    {
        [$tenant, $property, $guide] = $this->tenantEnvironment(MembershipRole::Guide);
        $own = Resource::factory()->guide()->create(['property_id' => $property->id, 'user_id' => $guide->id]);
        $other = Resource::factory()->guide()->create(['property_id' => $property->id]);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id, 'status' => ReservationStatus::Confirmed,
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2),
        ]);
        Allocation::query()->create([
            'reservation_id' => $reservation->id, 'requested_category_id' => $this->category($property, 'guide')->id,
            'resource_id' => $own->id, 'status' => AllocationStatus::Confirmed,
            'starts_at' => $reservation->starts_at, 'ends_at' => $reservation->ends_at, 'quantity' => 1,
        ]);
        $ownTask = OperationalTask::query()->create([
            'property_id' => $property->id, 'reservation_id' => $reservation->id, 'assignee_id' => $guide->id,
            'title' => 'Meet guests', 'priority' => 'normal', 'status' => TaskStatus::Todo,
        ]);
        $otherTask = OperationalTask::query()->create([
            'property_id' => $property->id, 'title' => 'Manager-only task', 'priority' => 'normal', 'status' => TaskStatus::Todo,
        ]);

        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/calendar?start='.now()->toDateString().'&end='.now()->addDays(3)->toDateString())->assertOk();
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/resource-blocks', [
            'resource_id' => $own->id, 'reason' => 'Guide unavailable',
            'starts_at' => now()->addDays(3)->toIso8601String(), 'ends_at' => now()->addDays(3)->addHours(2)->toIso8601String(),
        ])->assertCreated();
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/resource-blocks', [
            'resource_id' => $other->id, 'reason' => 'Unauthorized block',
            'starts_at' => now()->addDays(3)->toIso8601String(), 'ends_at' => now()->addDays(3)->addHours(2)->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('resource_id');
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson("/api/v1/tasks/{$ownTask->id}")->assertOk();
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson("/api/v1/tasks/{$otherTask->id}")->assertForbidden();
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/guests')->assertForbidden();
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/operational-kpis?start=2026-08-01&end=2026-08-31')->assertForbidden();
    }

    public function test_calendar_plain_date_boundaries_are_property_local_and_half_open(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $property->update(['timezone' => 'America/Bogota']);
        $inside = Reservation::factory()->create([
            'property_id' => $property->id,
            'starts_at' => CarbonImmutable::parse('2026-09-10 05:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-09-10 06:00:00 UTC'),
        ]);
        $before = Reservation::factory()->create([
            'property_id' => $property->id,
            'starts_at' => CarbonImmutable::parse('2026-09-10 04:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-09-10 04:59:59 UTC'),
        ]);
        $atExclusiveEnd = Reservation::factory()->create([
            'property_id' => $property->id,
            'starts_at' => CarbonImmutable::parse('2026-09-11 05:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-09-11 06:00:00 UTC'),
        ]);

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/v1/calendar?start=2026-09-10&end=2026-09-11')
            ->assertOk()
            ->assertJsonPath('range.start', '2026-09-10T05:00:00+00:00')
            ->assertJsonPath('range.end', '2026-09-11T05:00:00+00:00')
            ->assertJsonPath('range.timezone', 'America/Bogota');

        $ids = collect($response->json('data'))->where('type', 'reservation')->pluck('id');
        $this->assertTrue($ids->contains($inside->id));
        $this->assertFalse($ids->contains($before->id));
        $this->assertFalse($ids->contains($atExclusiveEnd->id));
    }

    public function test_dense_ninety_day_calendar_has_bounded_query_count_and_latency_receipt(): void
    {
        [, $property, $user] = $this->tenantEnvironment();
        $category = $this->category($property, 'room');
        $resources = collect(range(1, 40))->map(fn (int $index): Resource => Resource::factory()->create([
            'property_id' => $property->id, 'category_id' => $category->id,
            'name' => "Fixture room {$index}", 'code' => "FX-{$index}",
        ]));
        foreach (range(1, 120) as $index) {
            $start = CarbonImmutable::parse('2026-09-01 UTC')->addDays($index % 86);
            $reservation = Reservation::factory()->create([
                'property_id' => $property->id, 'status' => ReservationStatus::Confirmed,
                'confirmation_number' => sprintf('RSV-CALENDAR-%03d', $index),
                'starts_at' => $start, 'ends_at' => $start->addDays(2),
            ]);
            Allocation::query()->create([
                'reservation_id' => $reservation->id, 'requested_category_id' => $category->id,
                'resource_id' => $resources[$index % $resources->count()]->id,
                'status' => AllocationStatus::Confirmed, 'starts_at' => $start, 'ends_at' => $start->addDays(2), 'quantity' => 1,
            ]);
        }
        $projection = app(CalendarProjectionService::class);
        $start = CarbonImmutable::parse('2026-09-01 UTC');
        $end = $start->addDays(90);
        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });
        $timings = collect(range(1, 12))->map(function () use ($projection, $start, $end, $user, $property): float {
            $began = hrtime(true);
            $projection->build($start, $end, $user, $property->id);

            return round((hrtime(true) - $began) / 1_000_000, 2);
        })->sort()->values();
        $perRunQueries = (int) ceil($queryCount / $timings->count());
        $p50 = $timings[(int) floor(($timings->count() - 1) * .50)];
        $p95 = $timings[(int) floor(($timings->count() - 1) * .95)];
        fwrite(STDOUT, "\nOPERATIONAL_CALENDAR_BENCHMARK ".json_encode([
            'resources' => 40, 'reservations' => 120, 'days' => 90,
            'queries_max' => $perRunQueries, 'p50_ms' => $p50, 'p95_ms' => $p95,
        ])."\n");

        $this->assertLessThanOrEqual(20, $perRunQueries);
        $this->assertLessThan(750, $p50, 'Median dense-calendar projection latency exceeded the operational budget.');
        $this->assertLessThan(1500, $p95, 'Tail dense-calendar projection latency exceeded the shared-runner budget.');
    }
}
