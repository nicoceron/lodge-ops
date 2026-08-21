<?php

namespace Tests\Feature;

use App\Enums\AllocationStatus;
use App\Enums\BookingQuoteStatus;
use App\Enums\MembershipRole;
use App\Enums\ReservationStatus;
use App\Exceptions\CommercialWorkflowException;
use App\Filament\Resources\OperationalTasks\OperationalTaskResource;
use App\Filament\Resources\OperationalTasks\Pages\CreateOperationalTask;
use App\Filament\Resources\OperationalTasks\Pages\EditOperationalTask;
use App\Models\Allocation;
use App\Models\ChecklistTemplate;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Membership;
use App\Models\OperationalTask;
use App\Models\Program;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\User;
use App\Services\AllocationWorkflowService;
use App\Services\BookingQuoteService;
use App\Services\ChecklistWorkflowService;
use App\Services\CommitBookingQuote;
use App\Services\OperationalKpiService;
use App\Services\Payments\SensitivePaymentDataGuard;
use App\Services\ProposalService;
use App\Services\ReallocateResource;
use App\Services\SharedResourceAttentionService;
use App\Services\TaskLifecycleService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class OperationalAcceptanceSecondReviewTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_filament_task_writes_use_revisioned_lifecycle_events_and_never_hard_delete(): void
    {
        [$tenant, $property, $manager, $managerMembership] = $this->tenantEnvironment(MembershipRole::Manager, authenticate: false);
        $reservation = Reservation::factory()->create(['property_id' => $property->id]);
        $guide = User::factory()->create();
        Membership::factory()->create([
            'user_id' => $guide->id, 'property_id' => $property->id,
            'role' => MembershipRole::Guide, 'is_active' => true,
        ]);
        $this->actingAs($manager);
        app(TenantContext::class)->set($tenant, $managerMembership);
        Filament::setCurrentPanel(filament()->getPanel('admin'));
        Filament::setTenant($tenant, isQuiet: true);

        Livewire::test(CreateOperationalTask::class)
            ->fillForm([
                'property_id' => $property->id,
                'reservation_id' => $reservation->id,
                'assignee_id' => $guide->id,
                'title' => 'Guide safety briefing',
                'priority' => 'high',
                'description' => 'Review the route.',
            ])->call('create')->assertHasNoFormErrors();

        $task = OperationalTask::query()->where('title', 'Guide safety briefing')->firstOrFail();
        $this->assertSame('todo', $task->status->value);
        $this->assertSame(2, $task->revision);
        $this->assertSame(['created', 'assigned'], $task->events()->pluck('type')->all());
        $this->assertDatabaseHas('outbox', ['aggregate_id' => $task->id, 'event_type' => 'operational_task.created']);
        $this->assertDatabaseHas('outbox', ['aggregate_id' => $task->id, 'event_type' => 'operational_task.assigned']);

        Livewire::test(EditOperationalTask::class, ['record' => $task->id])
            ->fillForm(['title' => 'Guide safety briefing amended', 'description' => 'Route and radio review.', 'priority' => 'urgent'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame($property->id, $task->fresh()->property_id);
        $this->assertDatabaseHas('operational_task_events', ['operational_task_id' => $task->id, 'type' => 'details_updated']);
        $this->assertDatabaseHas('outbox', ['aggregate_id' => $task->id, 'event_type' => 'operational_task.details_updated']);
        $this->assertFalse(OperationalTaskResource::canDelete($task->fresh()));

        $guideMembership = Membership::query()->where('user_id', $guide->id)->firstOrFail();
        app(TenantContext::class)->set($tenant, $guideMembership);
        $this->actingAs($guide);
        try {
            app(TaskLifecycleService::class)->create([
                'property_id' => $property->id, 'title' => 'Unauthorized scheduled task',
            ], $guide->id);
            $this->fail('A Guide directly scheduled an operational task.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_checklist_generation_rejects_terminal_reservations_and_only_supersedes_selected_lineage(): void
    {
        [, $property, $manager] = $this->tenantEnvironment(MembershipRole::Manager);
        $workflow = app(ChecklistWorkflowService::class);
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'status' => ReservationStatus::Confirmed]);
        $firstTemplate = ChecklistTemplate::query()->create(['property_id' => $property->id, 'name' => 'Arrival', 'role' => 'operations']);
        $secondTemplate = ChecklistTemplate::query()->create(['property_id' => $property->id, 'name' => 'Kitchen', 'role' => 'kitchen']);
        $firstV1 = $workflow->publish($firstTemplate, [['title' => 'Arrival untouched']], $manager->id);
        $secondV1 = $workflow->publish($secondTemplate, [['title' => 'Kitchen untouched']], $manager->id);
        $workflow->generate($reservation, $firstV1, $manager->id);
        $workflow->generate($reservation, $secondV1, $manager->id);
        $secondV2 = $workflow->publish($secondTemplate, [['title' => 'Kitchen replacement']], $manager->id);
        $result = $workflow->generate($reservation, $secondV2, $manager->id);

        $this->assertSame(1, $result['superseded']);
        $this->assertDatabaseHas('operational_tasks', ['reservation_id' => $reservation->id, 'title' => 'Arrival untouched', 'status' => 'todo']);
        $this->assertDatabaseHas('operational_tasks', ['reservation_id' => $reservation->id, 'title' => 'Kitchen untouched', 'status' => 'superseded']);

        foreach ([ReservationStatus::Cancelled, ReservationStatus::NoShow, ReservationStatus::CheckedOut] as $terminal) {
            $terminalReservation = Reservation::factory()->create(['property_id' => $property->id, 'status' => $terminal]);
            try {
                $workflow->generate($terminalReservation, $firstV1, $manager->id);
                $this->fail("Checklist generation succeeded for {$terminal->value}.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_shared_resource_assign_move_swap_release_are_audited_and_healthy_rows_are_retained(): void
    {
        [, $property, $manager] = $this->tenantEnvironment(MembershipRole::Manager);
        $category = $this->category($property, 'guide');
        $program = Program::query()->create([
            'property_id' => $property->id, 'name' => 'Audited guides', 'default_duration_minutes' => 60,
            'capacity' => 8, 'price_minor' => 0, 'currency' => 'USD', 'is_active' => true,
        ]);
        $program->requirements()->create(['resource_category_id' => $category->id, 'minimum_quantity' => 1]);
        [$guideA, $guideB, $guideC] = Resource::factory()->guide()->count(3)->create([
            'property_id' => $property->id, 'category_id' => $category->id,
        ])->all();
        $starts = now()->addDays(8)->startOfHour();
        $ends = $starts->clone()->addHours(6);
        $primary = Reservation::factory()->create([
            'property_id' => $property->id, 'program_id' => $program->id,
            'status' => ReservationStatus::Confirmed, 'starts_at' => $starts, 'ends_at' => $ends,
        ]);
        $other = Reservation::factory()->create([
            'property_id' => $property->id, 'program_id' => $program->id,
            'status' => ReservationStatus::Confirmed, 'starts_at' => $starts, 'ends_at' => $ends,
        ]);
        $request = Allocation::query()->create([
            'reservation_id' => $primary->id, 'requested_category_id' => $category->id,
            'status' => AllocationStatus::Confirmed, 'starts_at' => $starts, 'ends_at' => $ends, 'quantity' => 1,
        ]);
        $otherAllocation = Allocation::query()->create([
            'reservation_id' => $other->id, 'resource_id' => $guideA->id,
            'status' => AllocationStatus::Confirmed, 'starts_at' => $starts, 'ends_at' => $ends, 'quantity' => 1,
        ]);

        app(ReallocateResource::class)->handle($primary, $request, $guideB, $manager->id, reason: 'Assign guide.');
        $assigned = $primary->allocations()->where('status', '!=', AllocationStatus::Released)->firstOrFail();
        $rows = app(SharedResourceAttentionService::class)->build($starts->toImmutable()->subHour(), $ends->toImmutable()->addHour(), $property->id, []);
        $this->assertSame('healthy', $rows->firstWhere('reservation_id', $primary->id)['attention_state']);

        app(ReallocateResource::class)->handle($primary, $assigned, $guideC, $manager->id, reason: 'Move guide.');
        $moved = $primary->allocations()->where('status', '!=', AllocationStatus::Released)->firstOrFail();
        app(ReallocateResource::class)->handle($primary, $moved, $guideA, $manager->id, $otherAllocation, 'Swap guides.');
        $swapped = $primary->allocations()->where('status', '!=', AllocationStatus::Released)->firstOrFail();
        app(AllocationWorkflowService::class)->release($primary, $swapped, $manager->id, 'Release guide.', true);

        foreach (['resource_assigned', 'resource_moved', 'resource_swapped', 'resource_released'] as $type) {
            $this->assertDatabaseHas('reservation_changes', ['reservation_id' => $primary->id, 'type' => $type]);
        }
        $this->assertDatabaseHas('reservation_changes', ['reservation_id' => $other->id, 'type' => 'resource_swapped']);
        $this->assertDatabaseHas('outbox', ['aggregate_id' => $primary->id, 'event_type' => 'reservation.resource_released']);
        $this->assertDatabaseHas('outbox', ['aggregate_id' => $other->id, 'event_type' => 'reservation.resource_reallocated']);
    }

    public function test_proposals_require_fresh_unique_quotes_latest_sent_version_and_replay_guest_identity(): void
    {
        [, $property, $manager] = $this->tenantEnvironment(MembershipRole::Manager);
        $guest = Guest::factory()->create();
        $otherGuest = Guest::factory()->create();
        $quote = $this->quote($property);
        $service = app(ProposalService::class);
        $proposal = $service->createDraft([
            'property_id' => $property->id, 'booking_quote_id' => $quote->id, 'primary_guest_id' => $guest->id,
        ], $manager->id);
        try {
            $service->createDraft([
                'property_id' => $property->id, 'booking_quote_id' => $quote->id, 'primary_guest_id' => $guest->id,
            ], $manager->id);
            $this->fail('The same quote backed multiple proposal versions.');
        } catch (CommercialWorkflowException) {
            $this->addToAssertionCount(1);
        }

        $service->send($proposal);
        $revision = $service->revise($proposal, $manager->id);
        $this->assertNotSame($quote->id, $revision->booking_quote_id);
        $this->assertSame(BookingQuoteStatus::Pending, $revision->bookingQuote()->firstOrFail()->status);
        try {
            $service->convertToReservation($proposal->fresh());
            $this->fail('A stale sent proposal version converted.');
        } catch (CommercialWorkflowException) {
            $this->addToAssertionCount(1);
        }

        $service->send($revision);
        $reservation = $service->convertToReservation($revision->fresh());
        $this->assertSame($guest->id, $reservation->primary_guest_id);

        $replayQuote = $this->quote($property);
        app(CommitBookingQuote::class)->handle($replayQuote, $guest->id);
        $this->expectException(ValidationException::class);
        app(CommitBookingQuote::class)->handle($replayQuote->fresh(), $otherGuest->id);
    }

    public function test_kpi_revenue_is_posting_window_property_and_currency_scoped_even_for_cancelled_earlier_stays(): void
    {
        [, $property] = $this->tenantEnvironment(MembershipRole::Manager);
        $cancelled = Reservation::factory()->create([
            'property_id' => $property->id, 'status' => ReservationStatus::Cancelled,
            'starts_at' => CarbonImmutable::parse('2026-06-01 UTC'), 'ends_at' => CarbonImmutable::parse('2026-06-05 UTC'),
            'currency' => 'USD',
        ]);
        FolioLine::query()->create([
            'reservation_id' => $cancelled->id, 'type' => 'charge', 'description' => 'Posted after cancellation',
            'quantity' => 1, 'unit_amount_minor' => 1234, 'net_amount_minor' => 1234,
            'tax_amount_minor' => 0, 'gross_amount_minor' => 1234, 'currency' => 'USD',
            'posted_at' => CarbonImmutable::parse('2026-08-10 12:00:00 UTC'),
        ]);
        $otherProperty = Property::factory()->create();
        $other = Reservation::factory()->create(['property_id' => $otherProperty->id, 'status' => ReservationStatus::Cancelled, 'currency' => 'USD']);
        FolioLine::query()->create([
            'reservation_id' => $other->id, 'type' => 'charge', 'description' => 'Other property',
            'quantity' => 1, 'unit_amount_minor' => 9999, 'net_amount_minor' => 9999,
            'tax_amount_minor' => 0, 'gross_amount_minor' => 9999, 'currency' => 'USD',
            'posted_at' => CarbonImmutable::parse('2026-08-10 12:00:00 UTC'),
        ]);

        $result = app(OperationalKpiService::class)->reconcile(
            CarbonImmutable::parse('2026-08-01 UTC'), CarbonImmutable::parse('2026-08-31 UTC'), 'UTC', $property->id,
        );
        $usd = collect(data_get($result, 'values.currencies'))->firstWhere('currency', 'USD');
        $this->assertSame(1234, $usd['revenue_minor']);
        $this->assertSame(0, $usd['booked_minor']);
    }

    public function test_document_email_sha256_is_exactly_recognized_without_pan_or_sad_weakening(): void
    {
        $guard = app(SensitivePaymentDataGuard::class);
        $generatedHash = hash('sha256', 'generated-document-email-38');
        $this->assertSame('68630223935050e7a317e114870567fb15aa2750e64f8670b775b3d727fa75b5', $generatedHash);
        $guard->assertSafe(['metadata' => ['document_email_key' => $generatedHash]]);
        $guard->assertSafe(['command_key' => $generatedHash], 'PaymentTenderDetail');

        foreach ([
            ['metadata' => ['document_email_key' => '4111111111111111']],
            ['metadata' => ['document_email_key' => 'CVV 123']],
            ['command_key' => '4111111111111111'],
            ['command_key' => 'CVV 123'],
        ] as $unsafe) {
            try {
                $guard->assertSafe($unsafe);
                $this->fail('A non-generated sensitive value bypassed the guard.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function quote(Property $property)
    {
        $category = $this->category($property, 'room');
        $room = Resource::factory()->create(['property_id' => $property->id, 'category_id' => $category->id, 'capacity' => 4]);
        $plan = RatePlan::query()->create(['property_id' => $property->id, 'name' => 'Review quote plan '.uniqid(), 'currency' => 'USD', 'maximum_occupancy' => 4]);
        RateRule::query()->create(['rate_plan_id' => $plan->id, 'resource_category_id' => $category->id, 'amount_minor' => 25_000]);
        $plan->forceFill(['state' => 'published', 'published_at' => now()])->save();

        return app(BookingQuoteService::class)->create([
            'property_id' => $property->id, 'rate_plan_id' => $plan->id,
            'resource_category_id' => $category->id, 'resource_id' => $room->id,
            'starts_at' => now()->addMonths(2), 'ends_at' => now()->addMonths(2)->addDays(2),
            'adults' => 2, 'children' => 0,
        ]);
    }
}
