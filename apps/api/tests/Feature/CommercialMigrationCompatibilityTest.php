<?php

namespace Tests\Feature;

use App\Models\RatePlan;
use App\Models\TaxRule;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class CommercialMigrationCompatibilityTest extends TestCase
{
    use CreatesTenant, DatabaseMigrations;

    public function test_legacy_active_versions_are_backfilled_without_inventing_fixed_tax_currency(): void
    {
        $path = 'database/migrations/2026_08_20_040001_add_commercial_rules_and_fiscal_readiness.php';
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]));
        [, $property] = $this->tenantEnvironment();
        $plan = RatePlan::query()->create(['property_id' => $property->id, 'name' => 'Legacy active', 'currency' => 'USD', 'is_active' => true]);
        $percentage = TaxRule::query()->create([
            'property_id' => $property->id, 'name' => 'Legacy percentage', 'calculation_type' => 'percentage',
            'percentage_basis_points' => 1900, 'is_active' => true,
        ]);
        $fixed = TaxRule::query()->create([
            'property_id' => $property->id, 'name' => 'Legacy fixed', 'calculation_type' => 'fixed',
            'fixed_amount_minor' => 500, 'is_active' => true,
        ]);

        $this->assertSame(0, Artisan::call('migrate', ['--path' => $path, '--force' => true]));
        $this->assertSame('published', $plan->fresh()->state);
        $this->assertNotNull($plan->fresh()->published_at);
        $this->assertSame('published', $percentage->fresh()->state);
        $this->assertSame('draft', $fixed->fresh()->state);
        $this->assertNull($fixed->fresh()->currency);
    }
}
