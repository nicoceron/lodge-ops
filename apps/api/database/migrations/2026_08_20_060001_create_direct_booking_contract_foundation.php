<?php

use App\Enums\DirectBookingErrorCode;
use App\Enums\DirectBookingOrderState;
use App\Enums\DirectBookingPaymentMethod;
use App\Enums\DirectBookingPublicationKind;
use App\Enums\DirectBookingPublicationState;
use App\Enums\DirectBookingTransitionAuthority;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_booking_property_settings', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->string('public_slug', 80)->unique();
            $table->boolean('direct_booking_enabled')->default(false);
            $table->string('default_locale', 12);
            $table->json('supported_locales');
            $table->char('default_currency', 3);
            $table->json('supported_currencies');
            $table->boolean('bot_verification_required')->default(true);
            $table->string('accessible_fallback_url', 500)->nullable();
            $table->unsignedSmallInteger('session_ttl_minutes')->default(120);
            $table->unsignedSmallInteger('initial_hold_minutes')->default(30);
            $table->unsignedSmallInteger('checkout_extension_minutes')->default(15);
            $table->unsignedSmallInteger('maximum_hold_minutes')->default(45);
            $table->unsignedSmallInteger('retention_days')->default(30);
            $table->timestamps();

            $table->unique(['tenant_id', 'property_id'], 'direct_booking_settings_property_unique');
            $table->foreign(['tenant_id', 'property_id'], 'direct_booking_settings_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
        });

        Schema::create('direct_booking_public_items', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->foreignUuid('resource_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('program_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('public_key', 26)->unique();
            $table->boolean('is_enabled')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign(['tenant_id', 'property_id'], 'direct_booking_items_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'resource_category_id'], 'direct_booking_items_tenant_category_fk')
                ->references(['tenant_id', 'id'])->on('resource_categories')->restrictOnDelete();
            $table->foreign(['tenant_id', 'program_id'], 'direct_booking_items_tenant_program_fk')
                ->references(['tenant_id', 'id'])->on('programs')->restrictOnDelete();
        });

        Schema::create('direct_booking_publications', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('public_item_id')->nullable()->constrained('direct_booking_public_items')->restrictOnDelete();
            $table->foreignUuid('supersedes_id')->nullable();
            $table->string('kind', 40);
            $table->string('locale', 12);
            $table->unsignedInteger('version');
            $table->string('state', 20)->default(DirectBookingPublicationState::Draft->value);
            $table->string('title', 200);
            $table->text('summary')->nullable();
            $table->text('body')->nullable();
            $table->json('content')->nullable();
            $table->char('checksum', 64);
            $table->timestampTz('effective_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'property_id', 'state', 'kind', 'locale'], 'direct_booking_publications_lookup_idx');
            $table->foreign(['tenant_id', 'property_id'], 'direct_booking_publications_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'public_item_id'], 'direct_booking_publications_tenant_item_fk')
                ->references(['tenant_id', 'id'])->on('direct_booking_public_items')->restrictOnDelete();
        });
        Schema::table('direct_booking_publications', function (Blueprint $table): void {
            $table->foreign('supersedes_id', 'direct_booking_publications_supersedes_fk')
                ->references('id')->on('direct_booking_publications')->restrictOnDelete();
        });

        Schema::create('direct_booking_public_media', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('publication_id')->constrained('direct_booking_publications')->cascadeOnDelete();
            $table->char('public_key', 26)->unique();
            $table->string('media_reference', 500);
            $table->string('mime_type', 100);
            $table->string('alt_text', 500);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign(['tenant_id', 'publication_id'], 'direct_booking_media_tenant_publication_fk')
                ->references(['tenant_id', 'id'])->on('direct_booking_publications')->cascadeOnDelete();
        });

        Schema::create('direct_booking_payment_capabilities', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->string('method', 32);
            $table->boolean('is_enabled')->default(false);
            $table->foreignUuid('provider_connection_id')->nullable()->constrained('integration_connections')->restrictOnDelete();
            $table->foreignUuid('instructions_publication_id')->nullable()->constrained('direct_booking_publications')->restrictOnDelete();
            $table->json('public_configuration')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'property_id', 'currency', 'method'], 'direct_booking_capabilities_unique');
            $table->foreign(['tenant_id', 'property_id'], 'direct_booking_capabilities_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'provider_connection_id'], 'direct_booking_capabilities_tenant_connection_fk')
                ->references(['tenant_id', 'id'])->on('integration_connections')->restrictOnDelete();
            $table->foreign(['tenant_id', 'instructions_publication_id'], 'direct_booking_capabilities_tenant_instructions_fk')
                ->references(['tenant_id', 'id'])->on('direct_booking_publications')->restrictOnDelete();
        });

        Schema::create('direct_booking_orders', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('booking_quote_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('reservation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('payment_request_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('public_reference', 26)->unique();
            $table->char('token_hash', 64)->unique();
            $table->string('state', 40)->default(DirectBookingOrderState::Started->value);
            $table->unsignedInteger('state_version')->default(1);
            $table->string('locale', 12);
            $table->char('currency', 3);
            $table->text('guest_contact_encrypted')->nullable();
            $table->char('guest_contact_checksum', 64)->nullable();
            $table->json('attribution')->nullable();
            $table->char('consent_checksum', 64)->nullable();
            $table->char('ip_prefix_hash', 64)->nullable();
            $table->string('safe_failure_code', 40)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('quoted_at')->nullable();
            $table->timestampTz('held_at')->nullable();
            $table->timestampTz('hold_extended_at')->nullable();
            $table->timestampTz('payment_started_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('token_rotated_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('retained_until');
            $table->timestamps();

            $table->index(['tenant_id', 'property_id', 'state', 'expires_at'], 'direct_booking_orders_expiry_idx');
            $table->foreign(['tenant_id', 'property_id'], 'direct_booking_orders_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
            $table->foreign(['tenant_id', 'booking_quote_id'], 'direct_booking_orders_tenant_quote_fk')
                ->references(['tenant_id', 'id'])->on('booking_quotes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reservation_id'], 'direct_booking_orders_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_request_id'], 'direct_booking_orders_tenant_payment_request_fk')
                ->references(['tenant_id', 'id'])->on('payment_requests')->restrictOnDelete();
        });

        Schema::create('direct_booking_order_consents', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('direct_booking_order_id')->constrained('direct_booking_orders')->restrictOnDelete();
            $table->foreignUuid('publication_id')->constrained('direct_booking_publications')->restrictOnDelete();
            $table->string('kind', 40);
            $table->unsignedInteger('publication_version');
            $table->char('publication_checksum', 64);
            $table->boolean('accepted');
            $table->char('ip_prefix_hash', 64)->nullable();
            $table->timestampTz('recorded_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'direct_booking_order_id', 'kind'], 'direct_booking_order_consents_kind_unique');
            $table->foreign(['tenant_id', 'direct_booking_order_id'], 'direct_booking_consents_tenant_order_fk')
                ->references(['tenant_id', 'id'])->on('direct_booking_orders')->restrictOnDelete();
            $table->foreign(['tenant_id', 'publication_id'], 'direct_booking_consents_tenant_publication_fk')
                ->references(['tenant_id', 'id'])->on('direct_booking_publications')->restrictOnDelete();
        });

        Schema::create('direct_booking_order_events', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('direct_booking_order_id')->constrained('direct_booking_orders')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('from_state', 40);
            $table->string('to_state', 40);
            $table->string('authority', 40);
            $table->string('retry_identity', 160);
            $table->char('request_checksum', 64);
            $table->unsignedInteger('state_version');
            $table->json('safe_metadata')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'direct_booking_order_id', 'sequence'], 'direct_booking_events_sequence_unique');
            $table->unique(['tenant_id', 'direct_booking_order_id', 'retry_identity'], 'direct_booking_events_retry_unique');
            $table->foreign(['tenant_id', 'direct_booking_order_id'], 'direct_booking_events_tenant_order_fk')
                ->references(['tenant_id', 'id'])->on('direct_booking_orders')->restrictOnDelete();
        });

        $this->addChecksAndPublishedIndexes();
    }

    public function down(): void
    {
        $hasFacts = DB::table('direct_booking_orders')->exists()
            || DB::table('direct_booking_order_events')->exists()
            || DB::table('direct_booking_order_consents')->exists();
        if ($hasFacts && ! app()->runningUnitTests()) {
            throw new RuntimeException('Direct-booking contract migration cannot be rolled back after immutable order, transition, or consent facts exist. Archive them and use a reviewed forward migration.');
        }

        $this->dropChecksAndPublishedIndexes();
        Schema::dropIfExists('direct_booking_order_events');
        Schema::dropIfExists('direct_booking_order_consents');
        Schema::dropIfExists('direct_booking_orders');
        Schema::dropIfExists('direct_booking_payment_capabilities');
        Schema::dropIfExists('direct_booking_public_media');
        Schema::dropIfExists('direct_booking_publications');
        Schema::dropIfExists('direct_booking_public_items');
        Schema::dropIfExists('direct_booking_property_settings');
    }

    private function addChecksAndPublishedIndexes(): void
    {
        $states = $this->quoted(DirectBookingOrderState::values());
        $authorities = $this->quoted(DirectBookingTransitionAuthority::values());
        $publicationKinds = $this->quoted(DirectBookingPublicationKind::values());
        $publicationStates = $this->quoted(DirectBookingPublicationState::values());
        $paymentMethods = $this->quoted(DirectBookingPaymentMethod::values());
        $errorCodes = $this->quoted(DirectBookingErrorCode::values());

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE direct_booking_public_items ADD CONSTRAINT direct_booking_public_items_subject_check CHECK ((kind = 'category' AND resource_category_id IS NOT NULL AND program_id IS NULL) OR (kind = 'program' AND program_id IS NOT NULL AND resource_category_id IS NULL))");
            DB::statement("ALTER TABLE direct_booking_publications ADD CONSTRAINT direct_booking_publications_kind_check CHECK (kind IN ({$publicationKinds}))");
            DB::statement("ALTER TABLE direct_booking_publications ADD CONSTRAINT direct_booking_publications_state_check CHECK (state IN ({$publicationStates}))");
            DB::statement("ALTER TABLE direct_booking_payment_capabilities ADD CONSTRAINT direct_booking_capabilities_method_check CHECK (method IN ({$paymentMethods}))");
            DB::statement("ALTER TABLE direct_booking_orders ADD CONSTRAINT direct_booking_orders_state_check CHECK (state IN ({$states}))");
            DB::statement("ALTER TABLE direct_booking_orders ADD CONSTRAINT direct_booking_orders_failure_check CHECK (safe_failure_code IS NULL OR safe_failure_code IN ({$errorCodes}))");
            DB::statement("ALTER TABLE direct_booking_order_events ADD CONSTRAINT direct_booking_events_from_state_check CHECK (from_state IN ({$states}))");
            DB::statement("ALTER TABLE direct_booking_order_events ADD CONSTRAINT direct_booking_events_to_state_check CHECK (to_state IN ({$states}))");
            DB::statement("ALTER TABLE direct_booking_order_events ADD CONSTRAINT direct_booking_events_authority_check CHECK (authority IN ({$authorities}))");
            DB::statement("CREATE UNIQUE INDEX direct_booking_one_published_idx ON direct_booking_publications (tenant_id, property_id, kind, locale, COALESCE(public_item_id, '00000000-0000-0000-0000-000000000000'::uuid)) WHERE state = 'published'");
            DB::statement("CREATE UNIQUE INDEX direct_booking_publications_version_unique ON direct_booking_publications (tenant_id, property_id, kind, locale, version, COALESCE(public_item_id, '00000000-0000-0000-0000-000000000000'::uuid))");
            DB::statement("CREATE UNIQUE INDEX direct_booking_items_category_unique ON direct_booking_public_items (tenant_id, property_id, resource_category_id) WHERE kind = 'category'");
            DB::statement("CREATE UNIQUE INDEX direct_booking_items_program_unique ON direct_booking_public_items (tenant_id, property_id, program_id) WHERE kind = 'program'");
            DB::statement('ALTER TABLE direct_booking_payment_capabilities ADD CONSTRAINT direct_booking_capabilities_currency_check CHECK (currency = upper(currency))');
            DB::statement('ALTER TABLE direct_booking_orders ADD CONSTRAINT direct_booking_orders_currency_check CHECK (currency = upper(currency))');

            return;
        }

        DB::statement("CREATE UNIQUE INDEX direct_booking_one_published_idx ON direct_booking_publications (tenant_id, property_id, kind, locale, ifnull(public_item_id, '00000000-0000-0000-0000-000000000000')) WHERE state = 'published'");
        DB::statement("CREATE UNIQUE INDEX direct_booking_publications_version_unique ON direct_booking_publications (tenant_id, property_id, kind, locale, version, ifnull(public_item_id, '00000000-0000-0000-0000-000000000000'))");
        DB::statement("CREATE UNIQUE INDEX direct_booking_items_category_unique ON direct_booking_public_items (tenant_id, property_id, resource_category_id) WHERE kind = 'category'");
        DB::statement("CREATE UNIQUE INDEX direct_booking_items_program_unique ON direct_booking_public_items (tenant_id, property_id, program_id) WHERE kind = 'program'");
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER direct_booking_items_values_insert BEFORE INSERT ON direct_booking_public_items BEGIN SELECT RAISE(ABORT, 'invalid public item subject') WHERE NOT ((NEW.kind = 'category' AND NEW.resource_category_id IS NOT NULL AND NEW.program_id IS NULL) OR (NEW.kind = 'program' AND NEW.program_id IS NOT NULL AND NEW.resource_category_id IS NULL)); END");
            DB::unprepared("CREATE TRIGGER direct_booking_publications_values_insert BEFORE INSERT ON direct_booking_publications BEGIN SELECT RAISE(ABORT, 'invalid publication value') WHERE NEW.kind NOT IN ({$publicationKinds}) OR NEW.state NOT IN ({$publicationStates}); END");
            DB::unprepared("CREATE TRIGGER direct_booking_capabilities_values_insert BEFORE INSERT ON direct_booking_payment_capabilities BEGIN SELECT RAISE(ABORT, 'invalid payment capability') WHERE NEW.method NOT IN ({$paymentMethods}) OR NEW.currency <> upper(NEW.currency); END");
            DB::unprepared("CREATE TRIGGER direct_booking_orders_values_insert BEFORE INSERT ON direct_booking_orders BEGIN SELECT RAISE(ABORT, 'invalid direct booking order') WHERE NEW.state NOT IN ({$states}) OR (NEW.safe_failure_code IS NOT NULL AND NEW.safe_failure_code NOT IN ({$errorCodes})) OR NEW.currency <> upper(NEW.currency); END");
            DB::unprepared("CREATE TRIGGER direct_booking_events_values_insert BEFORE INSERT ON direct_booking_order_events BEGIN SELECT RAISE(ABORT, 'invalid direct booking event') WHERE NEW.from_state NOT IN ({$states}) OR NEW.to_state NOT IN ({$states}) OR NEW.authority NOT IN ({$authorities}); END");
            DB::unprepared("CREATE TRIGGER direct_booking_items_values_update BEFORE UPDATE OF kind, resource_category_id, program_id ON direct_booking_public_items BEGIN SELECT RAISE(ABORT, 'invalid public item subject') WHERE NOT ((NEW.kind = 'category' AND NEW.resource_category_id IS NOT NULL AND NEW.program_id IS NULL) OR (NEW.kind = 'program' AND NEW.program_id IS NOT NULL AND NEW.resource_category_id IS NULL)); END");
            DB::unprepared("CREATE TRIGGER direct_booking_publications_values_update BEFORE UPDATE OF kind, state ON direct_booking_publications BEGIN SELECT RAISE(ABORT, 'invalid publication value') WHERE NEW.kind NOT IN ({$publicationKinds}) OR NEW.state NOT IN ({$publicationStates}); END");
            DB::unprepared("CREATE TRIGGER direct_booking_capabilities_values_update BEFORE UPDATE OF method, currency ON direct_booking_payment_capabilities BEGIN SELECT RAISE(ABORT, 'invalid payment capability') WHERE NEW.method NOT IN ({$paymentMethods}) OR NEW.currency <> upper(NEW.currency); END");
            DB::unprepared("CREATE TRIGGER direct_booking_orders_values_update BEFORE UPDATE OF state, safe_failure_code, currency ON direct_booking_orders BEGIN SELECT RAISE(ABORT, 'invalid direct booking order') WHERE NEW.state NOT IN ({$states}) OR (NEW.safe_failure_code IS NOT NULL AND NEW.safe_failure_code NOT IN ({$errorCodes})) OR NEW.currency <> upper(NEW.currency); END");
            DB::unprepared("CREATE TRIGGER direct_booking_events_values_update BEFORE UPDATE OF from_state, to_state, authority ON direct_booking_order_events BEGIN SELECT RAISE(ABORT, 'invalid direct booking event') WHERE NEW.from_state NOT IN ({$states}) OR NEW.to_state NOT IN ({$states}) OR NEW.authority NOT IN ({$authorities}); END");
        }
    }

    private function dropChecksAndPublishedIndexes(): void
    {
        DB::statement('DROP INDEX IF EXISTS direct_booking_one_published_idx');
        DB::statement('DROP INDEX IF EXISTS direct_booking_publications_version_unique');
        DB::statement('DROP INDEX IF EXISTS direct_booking_items_category_unique');
        DB::statement('DROP INDEX IF EXISTS direct_booking_items_program_unique');
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS direct_booking_items_values_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS direct_booking_publications_values_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS direct_booking_capabilities_values_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS direct_booking_orders_values_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS direct_booking_events_values_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS direct_booking_items_values_update');
            DB::unprepared('DROP TRIGGER IF EXISTS direct_booking_publications_values_update');
            DB::unprepared('DROP TRIGGER IF EXISTS direct_booking_capabilities_values_update');
            DB::unprepared('DROP TRIGGER IF EXISTS direct_booking_orders_values_update');
            DB::unprepared('DROP TRIGGER IF EXISTS direct_booking_events_values_update');
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'direct_booking_public_items_subject_check' => 'direct_booking_public_items',
            'direct_booking_publications_kind_check' => 'direct_booking_publications',
            'direct_booking_publications_state_check' => 'direct_booking_publications',
            'direct_booking_capabilities_method_check' => 'direct_booking_payment_capabilities',
            'direct_booking_capabilities_currency_check' => 'direct_booking_payment_capabilities',
            'direct_booking_orders_state_check' => 'direct_booking_orders',
            'direct_booking_orders_failure_check' => 'direct_booking_orders',
            'direct_booking_orders_currency_check' => 'direct_booking_orders',
            'direct_booking_events_from_state_check' => 'direct_booking_order_events',
            'direct_booking_events_to_state_check' => 'direct_booking_order_events',
            'direct_booking_events_authority_check' => 'direct_booking_order_events',
        ] as $constraint => $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
        }
    }

    /** @param list<string> $values */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(fn (string $value): string => DB::getPdo()->quote($value), $values));
    }

    private function tenantUuid(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
        $table->unique(['tenant_id', 'id']);
    }
};
