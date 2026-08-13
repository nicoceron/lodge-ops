<?php

namespace Tests\Feature;

use App\Filament\Resources\Reservations\Pages\ViewReservation;
use App\Filament\Resources\Reservations\RelationManagers\AllocationsRelationManager;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Resource;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class ResourceSuggestionFilamentTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    protected function tearDown(): void
    {
        Filament::setTenant(null, isQuiet: true);
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    public function test_staff_can_request_property_scoped_resource_suggestions_from_a_reservation(): void
    {
        [$tenant, $property, $user] = $this->tenantEnvironment(authenticate: false);
        Resource::factory()->create([
            'property_id' => $property->id,
            'name' => 'Recommended Guide',
            'category_id' => $this->category($property, 'guide')->id,
            'capacity' => 2,
        ]);
        $otherProperty = Property::factory()->create(['tenant_id' => $tenant->id]);
        Resource::factory()->create([
            'property_id' => $otherProperty->id,
            'name' => 'Other Property Guide',
            'category_id' => $this->category($otherProperty, 'guide')->id,
            'capacity' => 2,
        ]);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
        ]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($tenant, isQuiet: true);

        Livewire::test(AllocationsRelationManager::class, [
            'ownerRecord' => $reservation,
            'pageClass' => ViewReservation::class,
        ])
            ->assertTableActionExists('suggestResource')
            ->callTableAction('suggestResource', data: [
                'category_id' => $this->category($property, 'guide')->id,
                'starts_at' => $reservation->starts_at,
                'ends_at' => $reservation->ends_at,
                'quantity' => 1,
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Recommended: Recommended Guide');
    }
}
