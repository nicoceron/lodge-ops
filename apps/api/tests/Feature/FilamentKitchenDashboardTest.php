<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\ReservationStatus;
use App\Filament\Pages\KitchenDashboard;
use App\Models\Guest;
use App\Models\GuestPortalProfile;
use App\Models\Property;
use App\Models\Reservation;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class FilamentKitchenDashboardTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        app(TenantContext::class)->clear();
        Filament::setTenant(null, isQuiet: true);
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    public function test_kitchen_planner_uses_the_selected_property_timezone_and_date_range_without_guest_identity(): void
    {
        CarbonImmutable::setTestNow('2026-08-11 01:00:00 UTC');
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment(MembershipRole::Kitchen, authenticate: false);
        $tenant->update(['timezone' => 'UTC']);
        $property->update(['timezone' => 'America/Los_Angeles']);
        $guest = Guest::factory()->create([
            'first_name' => 'Private',
            'last_name' => 'Kitchen Guest',
            'preferences' => ['dietary' => ['Celiac-safe']],
        ]);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
            'status' => ReservationStatus::Confirmed,
            'starts_at' => '2026-08-12 07:00:00 UTC',
            'ends_at' => '2026-08-13 07:00:00 UTC',
        ]);
        GuestPortalProfile::query()->create([
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'profile' => [],
            'travel' => [],
            'preferences' => [
                'dietary_style' => 'Vegetarian',
                'allergies' => 'Severe nut allergy',
            ],
            'consented_at' => now(),
        ]);
        Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Confirmed,
            'starts_at' => '2026-08-14 07:00:00 UTC',
            'ends_at' => '2026-08-15 07:00:00 UTC',
        ]);
        $this->prepareFilament($tenant, $membership, $user);

        Livewire::test(KitchenDashboard::class)
            ->set('start', '2026-08-12')
            ->set('end', '2026-08-13')
            ->assertSee('August 12, 2026')
            ->assertSee('August 13, 2026')
            ->assertSee('America/Los_Angeles')
            ->assertSee('Celiac-safe')
            ->assertSee('Vegetarian')
            ->assertSee('Severe nut allergy')
            ->assertDontSee('Private Kitchen Guest');
    }

    public function test_kitchen_planner_cannot_select_a_property_outside_its_membership_scope(): void
    {
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment(MembershipRole::Kitchen, authenticate: false);
        $otherProperty = Property::factory()->create([
            'timezone' => 'America/New_York',
        ]);
        $ownGuest = Guest::factory()->create(['preferences' => ['dietary' => ['Own property only']]]);
        $otherGuest = Guest::factory()->create(['preferences' => ['dietary' => ['Other property leak']]]);
        Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $ownGuest->id,
            'status' => ReservationStatus::Confirmed,
            'starts_at' => '2026-08-12 12:00:00 UTC',
            'ends_at' => '2026-08-13 12:00:00 UTC',
        ]);
        Reservation::factory()->create([
            'property_id' => $otherProperty->id,
            'primary_guest_id' => $otherGuest->id,
            'status' => ReservationStatus::Confirmed,
            'starts_at' => '2026-08-12 12:00:00 UTC',
            'ends_at' => '2026-08-13 12:00:00 UTC',
        ]);
        $this->prepareFilament($tenant, $membership, $user);

        Livewire::test(KitchenDashboard::class)
            ->set('propertyId', $otherProperty->id)
            ->set('start', '2026-08-12')
            ->set('end', '2026-08-12')
            ->assertSee('Own property only')
            ->assertDontSee('Other property leak');
    }

    private function prepareFilament(object $tenant, object $membership, object $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(filament()->getPanel('admin'));
        Filament::setTenant($tenant, isQuiet: true);
        app(TenantContext::class)->set($tenant, $membership);
    }
}
