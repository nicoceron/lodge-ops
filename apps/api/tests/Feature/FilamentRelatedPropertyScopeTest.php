<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\PaymentStatus;
use App\Filament\Resources\CostRecords\CostRecordResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\CostRecord;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class FilamentRelatedPropertyScopeTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_property_scoped_finance_membership_cannot_browse_payments_from_another_property(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Finance);
        $otherProperty = Property::factory()->create(['tenant_id' => $tenant->id]);
        $visibleReservation = Reservation::factory()->create(['property_id' => $property->id]);
        $hiddenReservation = Reservation::factory()->create(['property_id' => $otherProperty->id]);

        $visible = Payment::query()->create([
            'reservation_id' => $visibleReservation->id,
            'status' => PaymentStatus::Succeeded,
            'method' => 'bank_transfer',
            'currency' => 'USD',
            'amount_minor' => 10000,
        ]);
        $hidden = Payment::query()->create([
            'reservation_id' => $hiddenReservation->id,
            'status' => PaymentStatus::Succeeded,
            'method' => 'bank_transfer',
            'currency' => 'USD',
            'amount_minor' => 20000,
        ]);

        $ids = PaymentResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
        $this->assertTrue(PaymentResource::canView($visible));
        $this->assertFalse(PaymentResource::canView($hidden));
    }

    public function test_property_scoped_finance_membership_cannot_browse_costs_from_another_property(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Finance);
        $otherProperty = Property::factory()->create(['tenant_id' => $tenant->id]);
        $visibleReservation = Reservation::factory()->create(['property_id' => $property->id]);
        $hiddenReservation = Reservation::factory()->create(['property_id' => $otherProperty->id]);
        $attributes = [
            'kind' => 'direct',
            'category' => 'guide',
            'description' => 'Scoped guide cost',
            'currency' => 'USD',
            'amount_minor' => 5000,
            'occurred_at' => now(),
        ];
        $visible = CostRecord::query()->create([...$attributes, 'reservation_id' => $visibleReservation->id]);
        $hidden = CostRecord::query()->create([...$attributes, 'reservation_id' => $hiddenReservation->id]);

        $ids = CostRecordResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
        $this->assertTrue(CostRecordResource::canView($visible));
        $this->assertFalse(CostRecordResource::canView($hidden));
    }
}
