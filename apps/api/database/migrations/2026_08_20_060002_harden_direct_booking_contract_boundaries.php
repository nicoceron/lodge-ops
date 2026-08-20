<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('direct_booking_orders', function (Blueprint $table): void {
            $table->timestampTz('session_expires_at')->nullable()->after('expires_at');
            $table->char('recovery_token_hash', 64)->nullable()->unique()->after('token_hash');
            $table->timestampTz('recovery_expires_at')->nullable()->after('session_expires_at');
            $table->timestampTz('quote_expires_at')->nullable()->after('quoted_at');
            $table->timestampTz('hold_expires_at')->nullable()->after('held_at');
            $table->timestampTz('checkout_expires_at')->nullable()->after('payment_started_at');
            $table->timestampTz('pii_scrubbed_at')->nullable()->after('retained_until');
            $table->timestampTz('guest_pii_cleanup_deferred_at')->nullable()->after('pii_scrubbed_at');
        });
        DB::table('direct_booking_orders')->update([
            'session_expires_at' => DB::raw('expires_at'),
            'recovery_expires_at' => DB::raw('retained_until'),
        ]);

        Schema::table('direct_booking_order_events', function (Blueprint $table): void {
            $table->string('event_type', 40)->default('transition')->after('direct_booking_order_id');
        });

        Schema::create('direct_booking_payment_instructions', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('direct_booking_payment_capability_id')
                ->constrained('direct_booking_payment_capabilities')->cascadeOnDelete();
            $table->foreignUuid('publication_id')->constrained('direct_booking_publications')->restrictOnDelete();
            $table->string('locale', 12);
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'property_id', 'direct_booking_payment_capability_id', 'locale'],
                'direct_booking_payment_instructions_locale_unique',
            );
            $table->foreign(['tenant_id', 'property_id'], 'direct_booking_payment_instructions_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
        });

        $this->addPropertyBoundaryConstraints();
    }

    public function down(): void
    {
        $this->dropPropertyBoundaryConstraints();
        Schema::dropIfExists('direct_booking_payment_instructions');
        Schema::table('direct_booking_order_events', function (Blueprint $table): void {
            $table->dropColumn('event_type');
        });
        Schema::table('direct_booking_orders', function (Blueprint $table): void {
            $table->dropUnique(['recovery_token_hash']);
            $table->dropColumn([
                'session_expires_at', 'recovery_token_hash', 'recovery_expires_at', 'quote_expires_at',
                'hold_expires_at', 'checkout_expires_at', 'pii_scrubbed_at', 'guest_pii_cleanup_deferred_at',
            ]);
        });
    }

    private function addPropertyBoundaryConstraints(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach ([
                'resource_categories' => 'resource_categories_tenant_property_id_unique',
                'programs' => 'programs_tenant_property_id_unique',
                'booking_quotes' => 'booking_quotes_tenant_property_id_unique',
                'reservations' => 'reservations_tenant_property_id_unique',
                'payment_requests' => 'payment_requests_tenant_property_id_unique',
                'direct_booking_public_items' => 'direct_booking_items_tenant_property_id_unique',
                'direct_booking_publications' => 'direct_booking_publications_tenant_property_id_unique',
                'direct_booking_payment_capabilities' => 'direct_booking_capabilities_tenant_property_id_unique',
            ] as $table => $index) {
                DB::statement("CREATE UNIQUE INDEX {$index} ON {$table} (tenant_id, property_id, id)");
            }

            foreach ([
                ['direct_booking_public_items', 'direct_booking_items_property_category_fk', 'resource_category_id', 'resource_categories'],
                ['direct_booking_public_items', 'direct_booking_items_property_program_fk', 'program_id', 'programs'],
                ['direct_booking_publications', 'direct_booking_publications_property_item_fk', 'public_item_id', 'direct_booking_public_items'],
                ['direct_booking_payment_capabilities', 'direct_booking_capabilities_property_instructions_fk', 'instructions_publication_id', 'direct_booking_publications'],
                ['direct_booking_orders', 'direct_booking_orders_property_quote_fk', 'booking_quote_id', 'booking_quotes'],
                ['direct_booking_orders', 'direct_booking_orders_property_reservation_fk', 'reservation_id', 'reservations'],
                ['direct_booking_orders', 'direct_booking_orders_property_payment_request_fk', 'payment_request_id', 'payment_requests'],
                ['direct_booking_payment_instructions', 'direct_booking_payment_instructions_capability_fk', 'direct_booking_payment_capability_id', 'direct_booking_payment_capabilities'],
                ['direct_booking_payment_instructions', 'direct_booking_payment_instructions_publication_fk', 'publication_id', 'direct_booking_publications'],
            ] as [$table, $constraint, $column, $referenced]) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} FOREIGN KEY (tenant_id, property_id, {$column}) REFERENCES {$referenced} (tenant_id, property_id, id) ON DELETE RESTRICT");
            }
            DB::statement('ALTER TABLE direct_booking_orders ADD CONSTRAINT direct_booking_orders_session_expiry_check CHECK (session_expires_at IS NOT NULL)');
            DB::statement("ALTER TABLE direct_booking_order_events ADD CONSTRAINT direct_booking_events_type_check CHECK (event_type IN ('transition', 'pii_scrubbed'))");

            return;
        }

        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        foreach ($this->sqliteBoundaryTriggers() as $name => $sql) {
            DB::unprepared("CREATE TRIGGER {$name} {$sql}");
        }
    }

    private function dropPropertyBoundaryConstraints(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (array_keys($this->sqliteBoundaryTriggers()) as $name) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
            }

            return;
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'direct_booking_payment_instructions_publication_fk' => 'direct_booking_payment_instructions',
            'direct_booking_payment_instructions_capability_fk' => 'direct_booking_payment_instructions',
            'direct_booking_orders_property_payment_request_fk' => 'direct_booking_orders',
            'direct_booking_orders_property_reservation_fk' => 'direct_booking_orders',
            'direct_booking_orders_property_quote_fk' => 'direct_booking_orders',
            'direct_booking_capabilities_property_instructions_fk' => 'direct_booking_payment_capabilities',
            'direct_booking_publications_property_item_fk' => 'direct_booking_publications',
            'direct_booking_items_property_program_fk' => 'direct_booking_public_items',
            'direct_booking_items_property_category_fk' => 'direct_booking_public_items',
            'direct_booking_orders_session_expiry_check' => 'direct_booking_orders',
            'direct_booking_events_type_check' => 'direct_booking_order_events',
        ] as $constraint => $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
        }
        foreach ([
            'resource_categories_tenant_property_id_unique', 'programs_tenant_property_id_unique',
            'booking_quotes_tenant_property_id_unique', 'reservations_tenant_property_id_unique',
            'payment_requests_tenant_property_id_unique', 'direct_booking_items_tenant_property_id_unique',
            'direct_booking_publications_tenant_property_id_unique', 'direct_booking_capabilities_tenant_property_id_unique',
        ] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
    }

    /** @return array<string, string> */
    private function sqliteBoundaryTriggers(): array
    {
        return [
            'direct_booking_items_property_insert' => "BEFORE INSERT ON direct_booking_public_items BEGIN SELECT RAISE(ABORT, 'cross-property public item subject') WHERE (NEW.resource_category_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM resource_categories s WHERE s.id = NEW.resource_category_id AND s.tenant_id = NEW.tenant_id AND s.property_id = NEW.property_id)) OR (NEW.program_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM programs s WHERE s.id = NEW.program_id AND s.tenant_id = NEW.tenant_id AND s.property_id = NEW.property_id)); END",
            'direct_booking_items_property_update' => "BEFORE UPDATE OF tenant_id, property_id, resource_category_id, program_id ON direct_booking_public_items BEGIN SELECT RAISE(ABORT, 'cross-property public item subject') WHERE (NEW.resource_category_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM resource_categories s WHERE s.id = NEW.resource_category_id AND s.tenant_id = NEW.tenant_id AND s.property_id = NEW.property_id)) OR (NEW.program_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM programs s WHERE s.id = NEW.program_id AND s.tenant_id = NEW.tenant_id AND s.property_id = NEW.property_id)); END",
            'direct_booking_publications_property_insert' => "BEFORE INSERT ON direct_booking_publications BEGIN SELECT RAISE(ABORT, 'cross-property public item publication') WHERE NEW.public_item_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM direct_booking_public_items s WHERE s.id = NEW.public_item_id AND s.tenant_id = NEW.tenant_id AND s.property_id = NEW.property_id); END",
            'direct_booking_publications_property_update' => "BEFORE UPDATE OF tenant_id, property_id, public_item_id ON direct_booking_publications BEGIN SELECT RAISE(ABORT, 'cross-property public item publication') WHERE NEW.public_item_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM direct_booking_public_items s WHERE s.id = NEW.public_item_id AND s.tenant_id = NEW.tenant_id AND s.property_id = NEW.property_id); END",
            'direct_booking_capabilities_property_insert' => "BEFORE INSERT ON direct_booking_payment_capabilities BEGIN SELECT RAISE(ABORT, 'cross-property payment instructions') WHERE NEW.instructions_publication_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM direct_booking_publications s WHERE s.id = NEW.instructions_publication_id AND s.tenant_id = NEW.tenant_id AND s.property_id = NEW.property_id); END",
            'direct_booking_capabilities_property_update' => "BEFORE UPDATE OF tenant_id, property_id, instructions_publication_id ON direct_booking_payment_capabilities BEGIN SELECT RAISE(ABORT, 'cross-property payment instructions') WHERE NEW.instructions_publication_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM direct_booking_publications s WHERE s.id = NEW.instructions_publication_id AND s.tenant_id = NEW.tenant_id AND s.property_id = NEW.property_id); END",
            'direct_booking_orders_property_insert' => $this->sqliteOrderBoundary('INSERT'),
            'direct_booking_orders_property_update' => $this->sqliteOrderBoundary('UPDATE OF tenant_id, property_id, booking_quote_id, reservation_id, payment_request_id'),
            'direct_booking_payment_instructions_insert' => $this->sqliteInstructionBoundary('INSERT'),
            'direct_booking_payment_instructions_update' => $this->sqliteInstructionBoundary('UPDATE OF tenant_id, property_id, direct_booking_payment_capability_id, publication_id'),
            'direct_booking_orders_session_expiry_insert' => "BEFORE INSERT ON direct_booking_orders BEGIN SELECT RAISE(ABORT, 'session expiry required') WHERE NEW.session_expires_at IS NULL; END",
            'direct_booking_orders_session_expiry_update' => "BEFORE UPDATE OF session_expires_at ON direct_booking_orders BEGIN SELECT RAISE(ABORT, 'session expiry required') WHERE NEW.session_expires_at IS NULL; END",
            'direct_booking_events_type_insert' => "BEFORE INSERT ON direct_booking_order_events BEGIN SELECT RAISE(ABORT, 'invalid direct booking event type') WHERE NEW.event_type NOT IN ('transition', 'pii_scrubbed'); END",
            'direct_booking_events_type_update' => "BEFORE UPDATE OF event_type ON direct_booking_order_events BEGIN SELECT RAISE(ABORT, 'invalid direct booking event type') WHERE NEW.event_type NOT IN ('transition', 'pii_scrubbed'); END",
        ];
    }

    private function sqliteOrderBoundary(string $operation): string
    {
        return "BEFORE {$operation} ON direct_booking_orders BEGIN SELECT RAISE(ABORT, 'cross-property direct booking order reference') WHERE (NEW.booking_quote_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM booking_quotes s WHERE s.id = NEW.booking_quote_id AND s.tenant_id = NEW.tenant_id AND s.property_id = NEW.property_id)) OR (NEW.reservation_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM reservations s WHERE s.id = NEW.reservation_id AND s.tenant_id = NEW.tenant_id AND s.property_id = NEW.property_id)) OR (NEW.payment_request_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM payment_requests s WHERE s.id = NEW.payment_request_id AND s.tenant_id = NEW.tenant_id AND s.property_id = NEW.property_id)); END";
    }

    private function sqliteInstructionBoundary(string $operation): string
    {
        return "BEFORE {$operation} ON direct_booking_payment_instructions BEGIN SELECT RAISE(ABORT, 'cross-property localized payment instructions') WHERE NOT EXISTS (SELECT 1 FROM direct_booking_payment_capabilities s WHERE s.id = NEW.direct_booking_payment_capability_id AND s.tenant_id = NEW.tenant_id AND s.property_id = NEW.property_id) OR NOT EXISTS (SELECT 1 FROM direct_booking_publications s WHERE s.id = NEW.publication_id AND s.tenant_id = NEW.tenant_id AND s.property_id = NEW.property_id); END";
    }

    private function tenantUuid(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
        $table->unique(['tenant_id', 'id']);
    }
};
