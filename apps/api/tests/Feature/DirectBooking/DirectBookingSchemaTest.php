<?php

namespace Tests\Feature\DirectBooking;

use App\Enums\BookingQuoteStatus;
use App\Enums\DirectBookingPublicationKind;
use App\Enums\DirectBookingPublicationState;
use App\Enums\PaymentRequestPurpose;
use App\Enums\PaymentRequestState;
use App\Models\BookingQuote;
use App\Models\DirectBookingPaymentCapability;
use App\Models\DirectBookingPaymentInstruction;
use App\Models\DirectBookingPropertySetting;
use App\Models\DirectBookingPublication;
use App\Models\DirectBookingPublicItem;
use App\Models\PaymentRequest;
use App\Models\Program;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Services\DirectBooking\DirectBookingTokenService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $this->assertRejected(fn () => DB::table('direct_booking_publications')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'property_id' => $property->id,
            'public_item_id' => $item->id, 'kind' => 'program', 'locale' => 'en', 'version' => 1,
            'state' => 'draft', 'title' => 'Wrong kind', 'checksum' => str_repeat('f', 64),
            'created_at' => now(), 'updated_at' => now(),
        ]));
        foreach (['category', 'program'] as $kind) {
            $this->assertRejected(fn () => DB::table('direct_booking_publications')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'property_id' => $property->id,
                'public_item_id' => null, 'kind' => $kind, 'locale' => 'en', 'version' => 1,
                'state' => 'draft', 'title' => 'Missing item', 'checksum' => str_repeat('e', 64),
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }
        try {
            DirectBookingPublication::query()->create([
                'property_id' => $property->id, 'public_item_id' => null,
                'kind' => DirectBookingPublicationKind::Category, 'locale' => 'en', 'version' => 1,
                'state' => DirectBookingPublicationState::Draft, 'title' => 'Missing item',
            ]);
            $this->fail('The model must reject category copy without a public item.');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
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

    public function test_property_composite_boundaries_reject_same_tenant_cross_property_links(): void
    {
        [$tenant, $propertyA] = $this->tenantEnvironment();
        $propertyB = Property::factory()->create();
        $categoryA = $this->category($propertyA, 'room');
        $this->category($propertyB, 'room');
        $programA = Program::query()->create([
            'property_id' => $propertyA->id, 'name' => 'A only', 'currency' => 'USD', 'is_active' => true,
        ]);
        $itemA = DirectBookingPublicItem::query()->create([
            'property_id' => $propertyA->id, 'kind' => 'category', 'resource_category_id' => $categoryA->id,
        ]);
        $publicationA = DirectBookingPublication::query()->create([
            'property_id' => $propertyA->id, 'public_item_id' => $itemA->id,
            'kind' => DirectBookingPublicationKind::Category, 'locale' => 'en', 'version' => 1,
            'state' => DirectBookingPublicationState::Published, 'title' => 'Room A', 'summary' => 'Only A.',
        ]);
        $capabilityA = DirectBookingPaymentCapability::query()->create([
            'property_id' => $propertyA->id, 'currency' => 'USD', 'method' => 'manual_bank_transfer',
        ]);

        foreach ([
            ['kind' => 'category', 'resource_category_id' => $categoryA->id, 'program_id' => null],
            ['kind' => 'program', 'resource_category_id' => null, 'program_id' => $programA->id],
        ] as $subject) {
            $this->assertRejected(fn () => DB::table('direct_booking_public_items')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'property_id' => $propertyB->id,
                ...$subject, 'public_key' => (string) Str::ulid(), 'is_enabled' => false, 'sort_order' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }
        $this->assertRejected(fn () => DB::table('direct_booking_publications')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'property_id' => $propertyB->id,
            'public_item_id' => $itemA->id, 'kind' => 'category', 'locale' => 'en', 'version' => 2,
            'state' => 'draft', 'title' => 'Cross property', 'checksum' => str_repeat('a', 64),
            'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->assertRejected(fn () => DB::table('direct_booking_payment_instructions')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'property_id' => $propertyB->id,
            'direct_booking_payment_capability_id' => $capabilityA->id, 'publication_id' => $publicationA->id,
            'locale' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]));

        $plan = RatePlan::query()->create([
            'property_id' => $propertyA->id, 'name' => 'A plan', 'currency' => 'USD', 'state' => 'draft', 'is_active' => true,
        ]);
        $quote = BookingQuote::query()->create([
            'property_id' => $propertyA->id, 'rate_plan_id' => $plan->id, 'resource_category_id' => $categoryA->id,
            'starts_at' => now()->addDays(10), 'ends_at' => now()->addDays(12), 'adults' => 2, 'children' => 0,
            'infants' => 0, 'currency' => 'USD', 'subtotal_minor' => 10_000, 'discount_minor' => 0,
            'tax_minor' => 0, 'total_minor' => 10_000, 'inputs' => [], 'calculation_snapshot' => [],
            'checksum' => str_repeat('b', 64), 'status' => BookingQuoteStatus::Pending, 'expires_at' => now()->addMinutes(20),
        ]);
        $reservation = Reservation::factory()->create(['property_id' => $propertyA->id, 'currency' => 'USD']);
        $request = PaymentRequest::query()->create([
            'property_id' => $propertyA->id, 'reservation_id' => $reservation->id,
            'public_id' => (string) Str::uuid(), 'access_token_hash' => hash('sha256', Str::random(64)),
            'purpose' => PaymentRequestPurpose::FullOutstanding, 'state' => PaymentRequestState::Open,
            'source_amount_minor' => 10_000, 'source_currency' => 'USD', 'calculation_snapshot' => [],
            'calculation_checksum' => str_repeat('c', 64), 'expires_at' => now()->addHour(),
        ]);
        $settingB = DirectBookingPropertySetting::query()->create([
            'property_id' => $propertyB->id, 'public_slug' => 'property-b', 'default_locale' => 'en',
            'supported_locales' => ['en'], 'default_currency' => 'USD', 'supported_currencies' => ['USD'],
        ]);
        $orderB = app(DirectBookingTokenService::class)->issue($settingB, 'en', 'USD')['order'];
        foreach (['booking_quote_id' => $quote->id, 'reservation_id' => $reservation->id, 'payment_request_id' => $request->id] as $column => $id) {
            $this->assertRejected(fn () => DB::table('direct_booking_orders')->where('id', $orderB->id)->update([$column => $id]));
        }
    }

    public function test_hardening_migration_round_trips_without_live_contract_facts(): void
    {
        $migration = require database_path('migrations/2026_08_20_060002_harden_direct_booking_contract_boundaries.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('direct_booking_payment_instructions'));
        $this->assertFalse(Schema::hasColumn('direct_booking_order_events', 'event_type'));
        $this->assertFalse(Schema::hasColumn('direct_booking_orders', 'session_expires_at'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('direct_booking_payment_instructions'));
        $this->assertTrue(Schema::hasColumn('direct_booking_order_events', 'event_type'));
        $this->assertTrue(Schema::hasColumn('direct_booking_orders', 'session_expires_at'));
    }

    public function test_command_response_migration_round_trips(): void
    {
        $migration = require database_path('migrations/2026_08_20_070001_create_direct_booking_command_responses.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('direct_booking_command_responses'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('direct_booking_command_responses'));
    }

    public function test_hardening_rollback_preflights_every_live_fact_before_any_ddl(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $setting = DirectBookingPropertySetting::query()->create([
            'property_id' => $property->id, 'public_slug' => 'rollback-guard', 'default_locale' => 'en',
            'supported_locales' => ['en'], 'default_currency' => 'USD', 'supported_currencies' => ['USD'],
        ]);
        $order = app(DirectBookingTokenService::class)->issue($setting, 'en', 'USD')['order'];
        $publication = DirectBookingPublication::query()->create([
            'property_id' => $property->id, 'kind' => DirectBookingPublicationKind::BankTransferInstructions,
            'locale' => 'en', 'version' => 1, 'state' => DirectBookingPublicationState::Published,
            'title' => 'Transfer instructions', 'body' => 'Use the quoted reference.',
        ]);
        $capability = DirectBookingPaymentCapability::query()->create([
            'property_id' => $property->id, 'currency' => 'USD', 'method' => 'manual_bank_transfer',
        ]);
        DirectBookingPaymentInstruction::query()->create([
            'property_id' => $property->id, 'direct_booking_payment_capability_id' => $capability->id,
            'publication_id' => $publication->id, 'locale' => 'en',
        ]);
        DB::table('direct_booking_order_events')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'direct_booking_order_id' => $order->id,
            'event_type' => 'pii_scrubbed', 'sequence' => 1, 'from_state' => 'started', 'to_state' => 'started',
            'authority' => 'scheduler', 'retry_identity' => 'rollback-guard-event-0001',
            'request_checksum' => str_repeat('a', 64), 'state_version' => 2, 'occurred_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_08_20_060002_harden_direct_booking_contract_boundaries.php');

        // The pgsql suite sets ALLOW_DIRECT_BOOKING_FACT_ROLLBACK=true so
        // DatabaseMigrations/RefreshDatabase can roll back the hardening migration
        // during teardown. This assertion must verify the production guard refuses
        // rollback when live facts exist, so override the config back to false
        // before invoking down() regardless of the global test environment.
        config(['direct-booking.allow_operational_fact_rollback' => false]);

        try {
            $migration->down();
            $this->fail('Live direct-booking hardening facts must block rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('localized payment instruction facts', $exception->getMessage());
            $this->assertStringContainsString('PII-scrub event facts', $exception->getMessage());
            $this->assertStringContainsString('live order session, recovery, quote, hold, checkout, or retention facts', $exception->getMessage());
            $this->assertStringContainsString('No DDL was changed', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('direct_booking_payment_instructions'));
        $this->assertTrue(Schema::hasColumn('direct_booking_order_events', 'event_type'));
        $this->assertTrue(Schema::hasColumn('direct_booking_orders', 'session_expires_at'));
        $this->assertDatabaseHas('direct_booking_order_events', ['id' => DB::table('direct_booking_order_events')->value('id'), 'event_type' => 'pii_scrubbed']);
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
