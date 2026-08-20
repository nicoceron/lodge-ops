<?php

namespace Tests\Feature\DirectBooking;

use App\Enums\DirectBookingPublicationKind;
use App\Enums\DirectBookingPublicationState;
use App\Models\DirectBookingPropertySetting;
use App\Models\DirectBookingPublication;
use App\Models\DirectBookingPublicItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class DirectBookingSchemaTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_database_rejects_invalid_subject_state_authority_currency_and_null_scope_duplicates(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $category = $this->category($property, 'room');
        DirectBookingPropertySetting::query()->create([
            'property_id' => $property->id, 'public_slug' => 'schema-property', 'default_locale' => 'en',
            'supported_locales' => ['en'], 'default_currency' => 'USD', 'supported_currencies' => ['USD'],
        ]);

        $this->assertRejected(fn () => DB::table('direct_booking_public_items')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'property_id' => $property->id,
            'kind' => 'category', 'resource_category_id' => null, 'program_id' => null,
            'public_key' => (string) Str::ulid(), 'is_enabled' => false, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]));

        $item = DirectBookingPublicItem::query()->create([
            'property_id' => $property->id, 'kind' => 'category', 'resource_category_id' => $category->id,
        ]);
        $this->assertRejected(fn () => DB::table('direct_booking_public_items')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'property_id' => $property->id,
            'kind' => 'category', 'resource_category_id' => $category->id, 'program_id' => null,
            'public_key' => (string) Str::ulid(), 'is_enabled' => false, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]));

        DirectBookingPublication::query()->create([
            'property_id' => $property->id, 'kind' => DirectBookingPublicationKind::Terms, 'locale' => 'en',
            'version' => 1, 'state' => DirectBookingPublicationState::Published, 'title' => 'Terms', 'body' => 'Published terms.',
        ]);
        $this->assertRejected(fn () => DirectBookingPublication::query()->create([
            'property_id' => $property->id, 'kind' => DirectBookingPublicationKind::Terms, 'locale' => 'en',
            'version' => 2, 'state' => DirectBookingPublicationState::Published, 'title' => 'Duplicate', 'body' => 'Must first retire.',
        ]));

        $this->assertRejected(fn () => DB::table('direct_booking_publications')->where('id', '<>', (string) Str::uuid())->update(['state' => 'unknown']));
        $this->assertRejected(fn () => DB::table('direct_booking_payment_capabilities')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'property_id' => $property->id,
            'currency' => 'usd', 'method' => 'hosted_checkout', 'is_enabled' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->assertNotNull($item->public_key);
    }

    public function test_composite_foreign_keys_reject_cross_tenant_public_subjects_and_order_references(): void
    {
        [$tenantA, $propertyA] = $this->tenantEnvironment();
        $categoryA = $this->category($propertyA, 'room');
        [$tenantB, $propertyB] = $this->tenantEnvironment();

        $this->assertRejected(fn () => DB::table('direct_booking_public_items')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantB->id, 'property_id' => $propertyB->id,
            'kind' => 'category', 'resource_category_id' => $categoryA->id, 'program_id' => null,
            'public_key' => (string) Str::ulid(), 'is_enabled' => false, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->assertNotSame($tenantA->id, $tenantB->id);
    }

    private function assertRejected(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('The database constraint should reject this operation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
