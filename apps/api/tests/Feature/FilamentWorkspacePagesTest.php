<?php

namespace Tests\Feature;

use App\Enums\AllocationStatus;
use App\Enums\MembershipRole;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\ResourceType;
use App\Enums\TaskStatus;
use App\Filament\Pages\KitchenDashboard;
use App\Models\Guest;
use App\Models\OperationalTask;
use App\Models\Payment;
use App\Models\Program;
use App\Models\Reservation;
use App\Models\Resource;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class FilamentWorkspacePagesTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        Filament::setTenant(null, isQuiet: true);
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    public function test_dashboard_is_an_actionable_lodge_command_center(): void
    {
        [$tenant, $property, $user] = $this->tenantEnvironment(authenticate: false);
        $guest = Guest::factory()->create(['first_name' => 'Dashboard', 'last_name' => 'Guest']);
        $arrival = CarbonImmutable::now($tenant->timezone)->startOfDay()->addHours(15)->utc();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
            'confirmation_number' => 'RSV-DASHBOARD-ARRIVAL',
            'status' => ReservationStatus::Confirmed,
            'starts_at' => $arrival,
            'ends_at' => $arrival->addDays(3),
        ]);
        $room = Resource::factory()->create(['property_id' => $property->id, 'name' => 'Dashboard Suite']);
        $reservation->allocations()->create([
            'resource_id' => $room->id,
            'status' => AllocationStatus::Confirmed,
            'starts_at' => $reservation->starts_at,
            'ends_at' => $reservation->ends_at,
            'quantity' => 1,
        ]);
        OperationalTask::query()->create([
            'property_id' => $property->id,
            'reservation_id' => $reservation->id,
            'title' => 'Confirm dashboard arrival transfer',
            'status' => TaskStatus::Todo,
            'priority' => 'high',
            'due_at' => now()->addMinutes(30),
        ]);
        $this->actingAs($user);

        $response = $this->get(Dashboard::getUrl(['tenant' => $tenant]));
        $response
            ->assertOk()
            ->assertSee('Next 7 days readiness')
            ->assertSee("Today's arrivals")
            ->assertSee('Dashboard Guest')
            ->assertSee('Action queue')
            ->assertSee('Confirm dashboard arrival transfer');
        $this->assertMatchesRegularExpression(
            '/<a(?=[^>]*wire:navigate)[^>]*>.*?New reservation.*?<\/a>/s',
            $response->getContent(),
        );
        $this->assertMatchesRegularExpression(
            '/<a(?=[^>]*wire:navigate)[^>]*>(?:(?!<\/a>).)*Confirm dashboard arrival transfer/s',
            $response->getContent(),
        );
    }

    public function test_owner_can_use_the_tenant_scoped_master_calendar(): void
    {
        [$tenant, $property, $user] = $this->tenantEnvironment(authenticate: false);
        $guest = Guest::factory()->create(['first_name' => 'Calendar', 'last_name' => 'Guest']);
        Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
            'confirmation_number' => 'RSV-FILAMENT-CALENDAR',
            'starts_at' => now()->addDay()->startOfHour(),
            'ends_at' => now()->addDays(3)->startOfHour(),
        ]);
        OperationalTask::query()->create([
            'property_id' => $property->id,
            'title' => 'Calendar room turn',
            'status' => TaskStatus::Todo,
            'priority' => 'high',
            'due_at' => now()->addDays(2),
        ]);
        $this->actingAs($user);

        $page = 'App\\Filament\\Pages\\MasterCalendar';
        $this->assertTrue(class_exists($page), 'The master calendar must be a Filament custom page.');

        $this->get($page::getUrl(['tenant' => $tenant]))
            ->assertOk()
            ->assertSee('Master calendar')
            ->assertSee('Calendar overview')
            ->assertSee('Allocation health')
            ->assertSee('Unassigned reservations')
            ->assertSee('RSV-FILAMENT-CALENDAR')
            ->assertSee('Calendar Guest')
            ->assertSee('Calendar room turn');
    }

    public function test_master_calendar_renders_each_programs_configured_color(): void
    {
        [$tenant, $property, $user] = $this->tenantEnvironment(authenticate: false);
        $program = Program::query()->create([
            'property_id' => $property->id,
            'name' => 'Red Stag Hunting',
            'display_color' => '#8C4438',
            'requires_accommodation' => true,
            'default_duration_minutes' => 4320,
            'capacity' => 6,
            'price_minor' => 1_250_000,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        Reservation::factory()->create([
            'property_id' => $property->id,
            'program_id' => $program->id,
            'confirmation_number' => 'RSV-RED-STAG-COLOR',
            'starts_at' => now()->addDay()->startOfHour(),
            'ends_at' => now()->addDays(3)->startOfHour(),
        ]);
        $this->actingAs($user);

        $page = 'App\\Filament\\Pages\\MasterCalendar';
        $this->get($page::getUrl(['tenant' => $tenant]))
            ->assertOk()
            ->assertSee('Program legend')
            ->assertSee('Red Stag Hunting')
            ->assertSee('#8C4438', false);
    }

    public function test_master_calendar_calls_out_full_lodge_buyouts(): void
    {
        [$tenant, $property, $user] = $this->tenantEnvironment(authenticate: false);
        $buyout = Resource::factory()->create([
            'property_id' => $property->id,
            'name' => 'Full lodge buyout',
            'code' => 'BUYOUT-TEST',
            'type' => ResourceType::Venue,
            'capacity' => 1,
            'is_buyout' => true,
        ]);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'confirmation_number' => 'RSV-FULL-BUYOUT',
            'status' => ReservationStatus::Confirmed,
            'starts_at' => now()->addDay()->startOfHour(),
            'ends_at' => now()->addDays(5)->startOfHour(),
        ]);
        $reservation->allocations()->create([
            'resource_id' => $buyout->id,
            'status' => AllocationStatus::Confirmed,
            'starts_at' => $reservation->starts_at,
            'ends_at' => $reservation->ends_at,
            'quantity' => 1,
        ]);
        $this->actingAs($user);

        $page = 'App\\Filament\\Pages\\MasterCalendar';
        $this->get($page::getUrl(['tenant' => $tenant]))
            ->assertOk()
            ->assertSee('Full lodge buyout active')
            ->assertSee('RSV-FULL-BUYOUT');
    }

    public function test_operations_user_can_work_the_live_operations_board(): void
    {
        [, $property, $user] = $this->tenantEnvironment(MembershipRole::Operations, authenticate: false);
        OperationalTask::query()->create([
            'property_id' => $property->id,
            'title' => 'Prepare north lodge arrival',
            'status' => TaskStatus::Todo,
            'due_at' => now()->addHours(3),
        ]);
        $this->actingAs($user);

        $page = 'App\\Filament\\Pages\\OperationsBoard';
        $this->assertTrue(class_exists($page), 'The operations board must be a Filament custom page.');

        $this->get($page::getUrl(['tenant' => $property->tenant]))
            ->assertOk()
            ->assertSee('Operations board')
            ->assertSee('Prepare north lodge arrival');
    }

    public function test_finance_user_can_review_the_tenant_finance_dashboard(): void
    {
        [$tenant, $property, $user] = $this->tenantEnvironment(MembershipRole::Finance, authenticate: false);
        $tenant->update(['currency' => 'USD']);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'confirmation_number' => 'RSV-FILAMENT-FINANCE',
            'status' => ReservationStatus::Confirmed,
            'currency' => 'USD',
            'total_minor' => 100_000,
            'starts_at' => now()->startOfHour(),
            'ends_at' => now()->addDays(2)->startOfHour(),
        ]);
        Payment::query()->create([
            'reservation_id' => $reservation->id,
            'status' => PaymentStatus::Succeeded,
            'method' => 'bank_transfer',
            'currency' => 'USD',
            'amount_minor' => 40_000,
            'processed_at' => now(),
        ]);
        $this->actingAs($user);

        $page = 'App\\Filament\\Pages\\FinanceDashboard';
        $this->assertTrue(class_exists($page), 'Finance reporting must be a Filament custom page.');

        $this->get($page::getUrl(['tenant' => $tenant]))
            ->assertOk()
            ->assertSee('Finance dashboard')
            ->assertSee('RSV-FILAMENT-FINANCE')
            ->assertSee('1,000.00')
            ->assertSee('400.00')
            ->assertSee('Revenue trend')
            ->assertSee('Program performance')
            ->assertSee('Channel performance')
            ->assertSee('Direct')
            ->assertSee('Reconciliation balanced');
    }

    public function test_kitchen_workspace_keeps_dietary_details_without_guest_identity(): void
    {
        [$tenant, $property, $user] = $this->tenantEnvironment(MembershipRole::Kitchen, authenticate: false);
        $guest = Guest::factory()->create([
            'first_name' => 'Private',
            'last_name' => 'Kitchen Guest',
            'preferences' => ['dietary' => ['Gluten-free', 'Severe shellfish allergy']],
        ]);
        $arrival = CarbonImmutable::now($tenant->timezone)->startOfDay()->addHours(15)->utc();
        Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
            'status' => ReservationStatus::Confirmed,
            'starts_at' => $arrival,
            'ends_at' => $arrival->addDays(2),
        ]);
        $this->actingAs($user);

        $this->get(KitchenDashboard::getUrl(['tenant' => $tenant]))
            ->assertOk()
            ->assertSee('Kitchen planning')
            ->assertSee('Gluten-free')
            ->assertSee('Severe shellfish allergy')
            ->assertDontSee('Private Kitchen Guest');
    }
}
