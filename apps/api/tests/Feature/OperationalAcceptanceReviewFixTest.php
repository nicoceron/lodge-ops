<?php

namespace Tests\Feature;

use App\Enums\AllocationStatus;
use App\Enums\MembershipRole;
use App\Enums\ReservationStatus;
use App\Enums\TaskStatus;
use App\Exceptions\CommercialWorkflowException;
use App\Filament\Pages\KitchenDashboard;
use App\Filament\Pages\MasterCalendar;
use App\Models\Allocation;
use App\Models\ChecklistTemplate;
use App\Models\Guest;
use App\Models\Membership;
use App\Models\OperationalTask;
use App\Models\Program;
use App\Models\Property;
use App\Models\Proposal;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\User;
use App\Services\ChecklistWorkflowService;
use App\Services\Payments\SensitivePaymentDataGuard;
use App\Services\Projections\CalendarProjectionService;
use App\Services\Projections\OperationsProjectionService;
use App\Services\ProposalService;
use App\Services\ReservationCompanionService;
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

class OperationalAcceptanceReviewFixTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_companion_operational_preferences_project_then_disappear_after_removal_or_cancellation(): void
    {
        [$tenant, $property, $manager] = $this->tenantEnvironment(MembershipRole::Manager);
        $today = CarbonImmutable::now($tenant->timezone)->startOfDay()->utc();
        $lead = Guest::factory()->create(['preferences' => null]);
        $companion = Guest::factory()->create(['preferences' => null]);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id, 'primary_guest_id' => $lead->id,
            'status' => ReservationStatus::Confirmed, 'starts_at' => $today,
            'ends_at' => $today->addDay(), 'adults' => 2, 'children' => 0,
        ]);
        $reservation->refresh();

        $before = app(OperationsProjectionService::class)->build($manager);
        $this->assertNotContains('Tree nuts', data_get($before, 'arrivals.0.dietary', []));
        $reservation = app(ReservationCompanionService::class)->replace($reservation, [[
            'guest_id' => $companion->id, 'dietary' => 'Vegetarian', 'allergies' => 'Tree nuts', 'meal_notes' => 'Separate prep',
        ]], $reservation->revision, $manager->id);

        $after = app(OperationsProjectionService::class)->build($manager);
        $this->assertContains('Tree nuts', data_get($after, 'arrivals.0.dietary', []));
        Livewire::test(KitchenDashboard::class)->assertSee('Tree nuts')->assertSee('Separate prep');

        $reservation = app(ReservationCompanionService::class)->replace($reservation, [], $reservation->revision, $manager->id);
        $this->assertNotContains('Tree nuts', data_get(app(OperationsProjectionService::class)->build($manager), 'arrivals.0.dietary', []));

        $reservation = app(ReservationCompanionService::class)->replace($reservation, [[
            'guest_id' => $companion->id, 'allergies' => 'Tree nuts',
        ]], $reservation->revision, $manager->id);
        $reservation->update(['status' => ReservationStatus::Cancelled]);
        $this->assertEmpty(app(OperationsProjectionService::class)->build($manager)['arrivals']);
        Livewire::test(KitchenDashboard::class)->assertDontSee('Tree nuts');
    }

    public function test_manual_proposals_are_nonconvertible_and_api_rejects_client_price_facts_or_quote_removal(): void
    {
        [$tenant, $property, $manager] = $this->tenantEnvironment(MembershipRole::Manager);
        $legacy = Proposal::query()->create([
            'property_id' => $property->id, 'reference' => 'LEGACY-1', 'version' => 1, 'status' => 'draft',
            'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addDay(), 'adults' => 1, 'children' => 0,
            'currency' => 'USD', 'total_minor' => 100, 'tax_minor' => 0, 'snapshot' => ['schema_version' => 1, 'lines' => []],
        ]);
        foreach (['send', 'revise', 'convertToReservation'] as $method) {
            try {
                $method === 'revise'
                    ? app(ProposalService::class)->revise($legacy, $manager->id)
                    : app(ProposalService::class)->{$method}($legacy);
                $this->fail("Legacy proposal action {$method} succeeded.");
            } catch (CommercialWorkflowException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/proposals', [
            'property_id' => $property->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('booking_quote_id');
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/proposals', [
            'property_id' => $property->id, 'booking_quote_id' => $legacy->id, 'tax_minor' => 1,
            'lines' => [['description' => 'client price', 'quantity_thousandths' => 1000, 'unit_amount_minor' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['tax_minor', 'lines']);
        $this->withHeader('X-Tenant-ID', $tenant->id)->patchJson("/api/v1/proposals/{$legacy->id}", [
            'booking_quote_id' => null, 'tax_minor' => 0, 'lines' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors(['booking_quote_id', 'tax_minor', 'lines']);
    }

    public function test_task_controller_is_revisioned_audited_and_assignee_validation_is_tenant_property_and_role_safe(): void
    {
        [$tenant, $property, $manager, $managerMembership] = $this->tenantEnvironment(MembershipRole::Manager);
        $guide = User::factory()->create();
        Membership::factory()->create(['user_id' => $guide->id, 'property_id' => $property->id, 'role' => MembershipRole::Guide]);
        $wrongRole = User::factory()->create();
        Membership::factory()->create(['user_id' => $wrongRole->id, 'property_id' => $property->id, 'role' => MembershipRole::Kitchen]);
        $inactive = User::factory()->create();
        Membership::factory()->create(['user_id' => $inactive->id, 'property_id' => $property->id, 'role' => MembershipRole::Guide, 'is_active' => false]);
        $wrongProperty = Property::factory()->create();
        $outsideProperty = User::factory()->create();
        Membership::factory()->create(['user_id' => $outsideProperty->id, 'property_id' => $wrongProperty->id, 'role' => MembershipRole::Guide]);
        $task = OperationalTask::query()->create([
            'property_id' => $property->id, 'title' => 'Lead trail', 'status' => TaskStatus::Todo,
            'priority' => 'normal', 'metadata' => ['checklist_role' => 'guide'],
        ]);

        $this->withHeader('X-Tenant-ID', $tenant->id)->putJson("/api/v1/tasks/{$task->id}", [
            'expected_revision' => 1, 'title' => 'Lead canyon trail',
        ])->assertOk()->assertJsonPath('data.revision', 2);
        $this->assertDatabaseHas('operational_task_events', ['operational_task_id' => $task->id, 'type' => 'details_updated']);
        $this->withHeader('X-Tenant-ID', $tenant->id)->putJson("/api/v1/tasks/{$task->id}", [
            'expected_revision' => 2, 'status' => 'done',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        foreach ([$wrongRole, $inactive, $outsideProperty] as $invalid) {
            $this->withHeader('X-Tenant-ID', $tenant->id)->postJson("/api/v1/tasks/{$task->id}/transition", [
                'action' => 'assign', 'expected_revision' => 2, 'assignee_id' => $invalid->id,
            ])->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
        }
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson("/api/v1/tasks/{$task->id}/transition", [
            'action' => 'assign', 'expected_revision' => 2, 'assignee_id' => $guide->id,
        ])->assertOk()->assertJsonPath('data.assignee_id', $guide->id);
        $this->withHeader('X-Tenant-ID', $tenant->id)->deleteJson("/api/v1/tasks/{$task->id}", [
            'expected_revision' => 3, 'reason' => 'Trip cancelled.',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('operational_tasks', ['id' => $task->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('operational_task_events', ['operational_task_id' => $task->id, 'type' => 'cancelled']);

        app(TenantContext::class)->set($tenant, $managerMembership);
        $terminalReservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Cancelled,
        ]);
        $terminalTask = OperationalTask::query()->create([
            'property_id' => $property->id,
            'reservation_id' => $terminalReservation->id,
            'title' => 'Terminal reservation task',
            'status' => TaskStatus::Done,
            'priority' => 'normal',
        ]);
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson("/api/v1/tasks/{$terminalTask->id}/transition", [
            'action' => 'reopen', 'expected_revision' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('reservation_reopen_authorized');
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson("/api/v1/tasks/{$terminalTask->id}/transition", [
            'action' => 'reopen', 'expected_revision' => 1, 'reservation_reopen_authorized' => true,
        ])->assertOk()->assertJsonPath('data.status', 'todo');
    }

    public function test_checklist_regeneration_preserves_nonpristine_work_and_validates_exception_applicability(): void
    {
        [$tenant, $property, $manager] = $this->tenantEnvironment(MembershipRole::Manager);
        Filament::setTenant($tenant, isQuiet: true);
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'status' => ReservationStatus::Confirmed]);
        $template = ChecklistTemplate::query()->create(['property_id' => $property->id, 'name' => 'Review checklist', 'role' => 'operations']);
        $workflow = app(ChecklistWorkflowService::class);
        $v1 = $workflow->publish($template, [
            ['title' => 'Untouched'], ['title' => 'Started'], ['title' => 'Failed'], ['title' => 'Escalated'],
        ], $manager->id);
        $workflow->generate($reservation, $v1, $manager->id);
        $tasks = OperationalTask::query()->where('reservation_id', $reservation->id)->get()->keyBy('title');
        app(TaskLifecycleService::class)->transition($tasks['Started'], 'start', ['expected_revision' => 1], $manager->id);
        app(TaskLifecycleService::class)->transition($tasks['Failed'], 'fail', ['expected_revision' => 1, 'reason' => 'Failed check'], $manager->id);
        app(TaskLifecycleService::class)->transition($tasks['Escalated'], 'escalate', ['expected_revision' => 1, 'reason' => 'Needs manager'], $manager->id);
        $v2 = $workflow->publish($template, [['title' => 'Replacement'], ['title' => 'Removed item']], $manager->id);
        $result = $workflow->generate($reservation, $v2, $manager->id);
        $this->assertSame(1, $result['superseded']);
        $this->assertSame(TaskStatus::InProgress, $tasks['Started']->fresh()->status);
        $this->assertSame(TaskStatus::Failed, $tasks['Failed']->fresh()->status);
        $this->assertSame(TaskStatus::Blocked, $tasks['Escalated']->fresh()->status);

        $item = $v2->items->firstOrFail();
        $removedItem = $v2->items->last();
        $workflow->replaceExceptions($reservation, [
            ['operation' => 'edit', 'checklist_template_item_id' => $item->id, 'title' => 'Edited replacement'],
            ['operation' => 'remove', 'checklist_template_item_id' => $removedItem->id],
            ['operation' => 'add', 'title' => 'VIP setup'],
        ], $manager->id);
        $generated = $workflow->generate($reservation, $v2, $manager->id);
        $this->assertSame(2, $generated['created']);
        $this->assertDatabaseHas('operational_tasks', ['reservation_id' => $reservation->id, 'title' => 'Edited replacement']);
        $this->assertDatabaseHas('operational_tasks', ['reservation_id' => $reservation->id, 'title' => 'VIP setup']);
        $this->assertDatabaseMissing('operational_tasks', [
            'reservation_id' => $reservation->id, 'title' => 'Removed item', 'generation' => $generated['generation'],
        ]);

        $exceptions = $reservation->checklistExceptions()->get()->keyBy('operation');
        $workflow->replaceExceptions($reservation, [
            ['id' => $exceptions['add']->id, 'operation' => 'add', 'title' => 'VIP setup'],
            ['id' => $exceptions['edit']->id, 'operation' => 'reorder', 'checklist_template_item_id' => $item->id, 'title' => 'Edited replacement'],
            ['id' => $exceptions['remove']->id, 'operation' => 'remove', 'checklist_template_item_id' => $removedItem->id],
        ], $manager->id);
        $this->assertDatabaseHas('reservation_checklist_exceptions', ['id' => $exceptions['add']->id, 'sort_order' => 0]);
        $this->assertDatabaseHas('reservation_checklist_exceptions', ['id' => $exceptions['edit']->id, 'operation' => 'reorder', 'sort_order' => 1]);
        $workflow->deleteException($reservation, $exceptions['add']->fresh());
        $afterDelete = $workflow->generate($reservation, $v2, $manager->id);
        $this->assertSame(1, $afterDelete['created']);
        $this->assertDatabaseMissing('operational_tasks', [
            'reservation_id' => $reservation->id, 'title' => 'VIP setup', 'generation' => $afterDelete['generation'],
        ]);

        $otherProperty = Property::factory()->create();
        $otherTemplate = ChecklistTemplate::query()->create(['property_id' => $otherProperty->id, 'name' => 'Other', 'role' => 'operations']);
        $otherVersion = $workflow->publish($otherTemplate, [['title' => 'Foreign item']], $manager->id);
        $this->expectException(ValidationException::class);
        $workflow->saveException($reservation, [
            'operation' => 'remove', 'checklist_template_item_id' => $otherVersion->items->firstOrFail()->id,
        ], $manager->id);
    }

    public function test_shared_resource_attention_workbench_assigns_and_conflict_ids_include_property_buyouts(): void
    {
        [$tenant, $property, $manager] = $this->tenantEnvironment(MembershipRole::Manager);
        Filament::setTenant($tenant, isQuiet: true);
        $program = Program::query()->create([
            'property_id' => $property->id, 'name' => 'Horse program', 'default_duration_minutes' => 60,
            'capacity' => 8, 'price_minor' => 0, 'currency' => 'USD', 'is_active' => true,
        ]);
        $guideCategory = $this->category($property, 'guide');
        $program->requirements()->create(['resource_category_id' => $guideCategory->id, 'minimum_quantity' => 1]);
        $guideResource = Resource::factory()->guide()->create(['property_id' => $property->id, 'category_id' => $guideCategory->id]);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id, 'program_id' => $program->id, 'status' => ReservationStatus::Confirmed,
            'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(3),
        ]);
        $request = Allocation::query()->create([
            'reservation_id' => $reservation->id, 'requested_category_id' => $guideCategory->id,
            'status' => AllocationStatus::Confirmed, 'starts_at' => $reservation->starts_at,
            'ends_at' => $reservation->ends_at, 'quantity' => 1,
        ]);
        $rows = app(SharedResourceAttentionService::class)->build(now()->toImmutable(), now()->addDays(4)->toImmutable(), $property->id, []);
        $this->assertSame('guide', $rows->first()['category_slug']);
        $this->assertSame($guideResource->id, $rows->first()['suggestions'][0]['id']);
        Livewire::test(MasterCalendar::class)->call('assignAttention', $reservation->id, $guideCategory->id, $guideResource->id, $request->id)->assertHasNoErrors();
        $this->assertDatabaseHas('allocations', ['reservation_id' => $reservation->id, 'resource_id' => $guideResource->id, 'status' => 'confirmed']);
        $this->assertDatabaseHas('allocations', ['id' => $request->id, 'status' => 'released']);

        $roomCategory = $this->category($property, 'room');
        $buyout = Resource::factory()->create(['property_id' => $property->id, 'category_id' => $this->category($property, 'venue')->id, 'is_buyout' => true]);
        $room = Resource::factory()->create(['property_id' => $property->id, 'category_id' => $roomCategory->id]);
        $first = Reservation::factory()->create(['property_id' => $property->id, 'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(6)]);
        $second = Reservation::factory()->create(['property_id' => $property->id, 'starts_at' => $first->starts_at, 'ends_at' => $first->ends_at]);
        foreach ([[$first, $buyout], [$second, $room]] as [$stay, $resource]) {
            Allocation::query()->create([
                'reservation_id' => $stay->id, 'resource_id' => $resource->id, 'status' => AllocationStatus::Confirmed,
                'starts_at' => $stay->starts_at, 'ends_at' => $stay->ends_at, 'quantity' => 1,
            ]);
        }
        $projection = app(CalendarProjectionService::class)->build(
            CarbonImmutable::now()->addDays(4), CarbonImmutable::now()->addDays(7), $manager, $property->id,
        );
        $this->assertContains($first->id, $projection['summary']['hard_conflict_reservation_ids']);
        $this->assertContains($second->id, $projection['summary']['hard_conflict_reservation_ids']);
        $this->assertTrue(collect($projection['summary']['hard_conflict_facts'])->contains('type', 'property_buyout_overlap'));
    }

    public function test_property_timezone_drives_kpi_window_and_nested_json_card_data_is_rejected(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Manager);
        $property->update(['timezone' => 'Pacific/Auckland']);
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson(
            "/api/v1/operational-kpis?start=2026-08-01&end=2026-08-02&property_id={$property->id}",
        )->assertOk()->assertJsonPath('data.range.timezone', 'Pacific/Auckland')
            ->assertJsonPath('data.range.property_id', $property->id)
            ->assertJsonPath('data.reconciliation.occupancy_balanced', true);

        $this->expectException(ValidationException::class);
        app(SensitivePaymentDataGuard::class)->assertSafe([
            'metadata' => json_encode(['payment' => ['raw' => '4111 1111 1111 1111']]),
        ]);
    }
}
