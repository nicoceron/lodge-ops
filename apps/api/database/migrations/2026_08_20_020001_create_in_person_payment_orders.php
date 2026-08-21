<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_requests', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->change();
            $table->char('access_token_hash', 64)->nullable()->change();
        });

        Schema::create('payment_terminals', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('integration_connection_id')->constrained()->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('environment', 16);
            $table->string('provider_account', 120);
            $table->string('provider_terminal_id', 160);
            $table->string('provider_store_id', 160)->nullable();
            $table->string('display_name', 120);
            $table->string('hardware_model', 80)->nullable();
            $table->string('serial_suffix', 16)->nullable();
            $table->string('operating_mode', 24);
            $table->boolean('is_enabled')->default(false);
            $table->string('health_state', 24)->default('unknown');
            $table->text('last_error')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampTz('last_successful_order_at')->nullable();
            $table->timestampTz('disabled_at')->nullable();
            $table->foreignUuid('replaced_by_id')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'provider', 'environment', 'provider_account', 'provider_terminal_id'], 'payment_terminals_provider_identity_unique');
            $table->index(['tenant_id', 'property_id', 'is_enabled'], 'payment_terminals_property_enabled_idx');
            $table->foreign(['tenant_id', 'property_id'], 'payment_terminals_tenant_property_fk')->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
            $table->foreign(['tenant_id', 'integration_connection_id'], 'payment_terminals_tenant_connection_fk')->references(['tenant_id', 'id'])->on('integration_connections')->restrictOnDelete();
        });
        Schema::table('payment_terminals', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'replaced_by_id'], 'payment_terminals_tenant_replacement_fk')->references(['tenant_id', 'id'])->on('payment_terminals')->restrictOnDelete();
        });

        Schema::create('provider_pos_locations', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('integration_connection_id')->constrained()->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('environment', 16);
            $table->string('provider_account', 120);
            $table->string('provider_store_id', 160);
            $table->string('external_pos_id', 160);
            $table->string('display_name', 120);
            $table->string('qr_mode', 16);
            $table->boolean('is_enabled')->default(false);
            $table->string('health_state', 24)->default('unknown');
            $table->text('last_error')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampTz('last_successful_order_at')->nullable();
            $table->timestampTz('disabled_at')->nullable();
            $table->foreignUuid('replaced_by_id')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'provider', 'environment', 'provider_account', 'external_pos_id'], 'provider_pos_provider_identity_unique');
            $table->index(['tenant_id', 'property_id', 'is_enabled'], 'provider_pos_property_enabled_idx');
            $table->foreign(['tenant_id', 'property_id'], 'provider_pos_tenant_property_fk')->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
            $table->foreign(['tenant_id', 'integration_connection_id'], 'provider_pos_tenant_connection_fk')->references(['tenant_id', 'id'])->on('integration_connections')->restrictOnDelete();
        });
        Schema::table('provider_pos_locations', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'replaced_by_id'], 'provider_pos_tenant_replacement_fk')->references(['tenant_id', 'id'])->on('provider_pos_locations')->restrictOnDelete();
        });

        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->string('channel', 32)->nullable()->default('online_checkout')->after('purpose');
            $table->foreignUuid('payment_terminal_id')->nullable()->after('integration_connection_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('provider_pos_location_id')->nullable()->after('payment_terminal_id')->constrained()->restrictOnDelete();
            $table->string('provider_order_id', 160)->nullable()->after('provider_preference_id');
            $table->string('provider_transaction_id', 160)->nullable()->after('provider_order_id');
            $table->string('provider_order_type', 16)->nullable()->after('provider_transaction_id');
            $table->string('qr_mode', 16)->nullable()->after('provider_order_type');
            $table->text('qr_data_ciphertext')->nullable()->after('qr_mode');
            $table->char('qr_data_checksum', 64)->nullable()->after('qr_data_ciphertext');
            $table->char('create_request_checksum', 64)->nullable()->after('idempotency_key');
            $table->string('cancel_idempotency_key', 160)->nullable()->after('create_request_checksum');
            $table->char('cancel_request_checksum', 64)->nullable()->after('cancel_idempotency_key');
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('at_terminal_at')->nullable();
            $table->timestampTz('action_required_at')->nullable();
            $table->timestampTz('order_expires_at')->nullable();
            $table->timestampTz('provider_order_created_at')->nullable();
            $table->timestampTz('provider_order_updated_at')->nullable();
            $table->timestampTz('cancel_requested_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->foreign(['tenant_id', 'payment_terminal_id'], 'payment_attempts_tenant_terminal_fk')->references(['tenant_id', 'id'])->on('payment_terminals')->restrictOnDelete();
            $table->foreign(['tenant_id', 'provider_pos_location_id'], 'payment_attempts_tenant_pos_fk')->references(['tenant_id', 'id'])->on('provider_pos_locations')->restrictOnDelete();
        });
        DB::table('payment_attempts')->whereNull('channel')->update(['channel' => 'online_checkout']);
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->string('channel', 32)->default('online_checkout')->nullable(false)->change();
            $table->unique(['tenant_id', 'provider', 'environment', 'provider_account', 'provider_order_id'], 'payment_attempts_order_unique');
            $table->unique(['tenant_id', 'provider', 'environment', 'provider_account', 'provider_transaction_id'], 'payment_attempts_order_transaction_unique');
        });

        Schema::table('provider_refunds', function (Blueprint $table): void {
            $table->string('provider_resource_type', 24)->default('payment')->after('provider_payment_id');
            $table->string('provider_order_id', 160)->nullable()->after('provider_resource_type');
            $table->string('provider_transaction_id', 160)->nullable()->after('provider_order_id');
            $table->char('operation_checksum', 64)->nullable()->after('idempotency_key');
            $table->boolean('provider_action_required')->default(false)->after('state');
            $table->text('provider_reason')->nullable()->after('last_error');
        });

        $this->installChecksAndActiveIndexes();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS payment_attempts_one_active_pos_idx');
            DB::statement('DROP INDEX IF EXISTS payment_attempts_one_active_terminal_idx');
            DB::statement('DROP INDEX IF EXISTS payment_attempts_one_reusable_idx');
            DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT IF EXISTS payment_attempts_state_check');
            DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT IF EXISTS payment_attempts_in_person_shape_check');
            DB::statement('ALTER TABLE payment_requests DROP CONSTRAINT IF EXISTS payment_requests_initiation_token_check');
            DB::statement("CREATE UNIQUE INDEX payment_attempts_one_reusable_idx ON payment_attempts (tenant_id, payment_request_id) WHERE state IN ('creating', 'checkout_ready', 'pending')");
            DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_state_check CHECK (state IN ('creating','checkout_ready','pending','approved','rejected','cancelled','expired','mismatched','failed'))");
        }
        Schema::table('provider_refunds', function (Blueprint $table): void {
            $table->dropColumn(['provider_resource_type', 'provider_order_id', 'provider_transaction_id', 'operation_checksum', 'provider_action_required', 'provider_reason']);
        });
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropUnique('payment_attempts_order_transaction_unique');
            $table->dropUnique('payment_attempts_order_unique');
            if (DB::getDriverName() === 'sqlite') {
                $table->dropForeign(['tenant_id', 'provider_pos_location_id']);
                $table->dropForeign(['tenant_id', 'payment_terminal_id']);
                $table->dropForeign(['provider_pos_location_id']);
                $table->dropForeign(['payment_terminal_id']);
            } else {
                $table->dropForeign('payment_attempts_tenant_pos_fk');
                $table->dropForeign('payment_attempts_tenant_terminal_fk');
                $table->dropForeign(['provider_pos_location_id']);
                $table->dropForeign(['payment_terminal_id']);
            }
            $table->dropColumn([
                'channel', 'payment_terminal_id', 'provider_pos_location_id', 'provider_order_id', 'provider_transaction_id',
                'provider_order_type', 'qr_mode', 'qr_data_ciphertext', 'qr_data_checksum', 'create_request_checksum',
                'cancel_idempotency_key', 'cancel_request_checksum', 'queued_at', 'at_terminal_at', 'action_required_at',
                'order_expires_at', 'cancel_requested_at', 'canceled_at',
                'provider_order_created_at', 'provider_order_updated_at',
            ]);
        });
        Schema::dropIfExists('provider_pos_locations');
        Schema::dropIfExists('payment_terminals');
        Schema::table('payment_requests', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable(false)->change();
            $table->char('access_token_hash', 64)->nullable(false)->change();
        });
    }

    private function installChecksAndActiveIndexes(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('DROP INDEX IF EXISTS payment_attempts_one_reusable_idx');
        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT IF EXISTS payment_attempts_state_check');
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_state_check CHECK (state IN ('creating','checkout_ready','pending','queued','at_terminal','action_required','processing','approved','rejected','cancelled','expired','mismatched','failed'))");
        DB::statement("ALTER TABLE payment_requests ADD CONSTRAINT payment_requests_initiation_token_check CHECK ((initiation_mode IN ('guest_link','direct_booking') AND public_id IS NOT NULL AND access_token_hash IS NOT NULL) OR (initiation_mode IN ('staff_point','staff_qr') AND public_id IS NULL AND access_token_hash IS NULL))");
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_in_person_shape_check CHECK ((channel = 'online_checkout' AND payment_terminal_id IS NULL AND provider_pos_location_id IS NULL AND provider_order_type IS NULL) OR (channel = 'integrated_terminal' AND payment_terminal_id IS NOT NULL AND provider_pos_location_id IS NULL AND provider_order_type = 'point') OR (channel = 'qr' AND provider_pos_location_id IS NOT NULL AND payment_terminal_id IS NULL AND provider_order_type = 'qr'))");
        $active = "'creating','checkout_ready','pending','queued','at_terminal','action_required','processing'";
        DB::statement("CREATE UNIQUE INDEX payment_attempts_one_reusable_idx ON payment_attempts (tenant_id, payment_request_id) WHERE state IN ({$active})");
        DB::statement("CREATE UNIQUE INDEX payment_attempts_one_active_terminal_idx ON payment_attempts (tenant_id, payment_terminal_id) WHERE payment_terminal_id IS NOT NULL AND state IN ({$active})");
        DB::statement("CREATE UNIQUE INDEX payment_attempts_one_active_pos_idx ON payment_attempts (tenant_id, provider_pos_location_id) WHERE provider_pos_location_id IS NOT NULL AND state IN ({$active})");
    }

    private function tenantUuid(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
        $table->unique(['tenant_id', 'id']);
    }
};
