<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_requests', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('reservation_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('deposit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('supersedes_id')->nullable();
            $table->foreignUuid('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->char('access_token_hash', 64)->unique();
            $table->string('initiation_mode', 24)->default('guest_link');
            $table->string('purpose', 32);
            $table->string('state', 24)->default('open');
            $table->bigInteger('source_amount_minor');
            $table->char('source_currency', 3);
            $table->char('charge_currency', 3)->nullable();
            $table->json('calculation_snapshot');
            $table->char('calculation_checksum', 64);
            $table->timestampTz('expires_at');
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('last_opened_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->unsignedInteger('access_count')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'payment_id']);
            $table->index(['tenant_id', 'property_id', 'state', 'expires_at'], 'payment_requests_work_idx');
            $table->index(['tenant_id', 'reservation_id', 'created_at'], 'payment_requests_reservation_idx');
            $table->foreign(['tenant_id', 'property_id'], 'payment_requests_tenant_property_fk')->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reservation_id'], 'payment_requests_tenant_reservation_fk')->references(['tenant_id', 'id'])->on('reservations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'deposit_id'], 'payment_requests_tenant_deposit_fk')->references(['tenant_id', 'id'])->on('deposits')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_id'], 'payment_requests_tenant_payment_fk')->references(['tenant_id', 'id'])->on('payments')->restrictOnDelete();
        });
        Schema::table('payment_requests', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'supersedes_id'], 'payment_requests_tenant_supersedes_fk')
                ->references(['tenant_id', 'id'])->on('payment_requests')->restrictOnDelete();
        });

        Schema::create('payment_attempts', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('reservation_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('payment_request_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('deposit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('integration_connection_id')->constrained()->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('environment', 16);
            $table->string('provider_account', 120);
            $table->uuid('external_reference');
            $table->uuid('idempotency_key');
            $table->string('purpose', 32);
            $table->string('state', 24)->default('creating');
            $table->bigInteger('source_amount_minor');
            $table->char('source_currency', 3);
            $table->bigInteger('charge_amount_minor');
            $table->char('charge_currency', 3);
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->json('conversion_snapshot')->nullable();
            $table->string('provider_preference_id')->nullable();
            $table->string('provider_payment_id')->nullable();
            $table->text('hosted_checkout_url')->nullable();
            $table->char('payer_hash', 64)->nullable();
            $table->timestampTz('checkout_expires_at')->nullable();
            $table->string('provider_status', 48)->nullable();
            $table->string('provider_status_detail', 120)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampTz('last_processed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'provider', 'environment', 'external_reference'], 'payment_attempts_external_unique');
            $table->unique(['tenant_id', 'provider', 'environment', 'provider_payment_id'], 'payment_attempts_payment_unique');
            $table->index(['tenant_id', 'state', 'updated_at'], 'payment_attempts_work_idx');
            $table->foreign(['tenant_id', 'property_id'], 'payment_attempts_tenant_property_fk')->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reservation_id'], 'payment_attempts_tenant_reservation_fk')->references(['tenant_id', 'id'])->on('reservations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_request_id'], 'payment_attempts_tenant_request_fk')->references(['tenant_id', 'id'])->on('payment_requests')->restrictOnDelete();
            $table->foreign(['tenant_id', 'deposit_id'], 'payment_attempts_tenant_deposit_fk')->references(['tenant_id', 'id'])->on('deposits')->restrictOnDelete();
            $table->foreign(['tenant_id', 'integration_connection_id'], 'payment_attempts_tenant_connection_fk')->references(['tenant_id', 'id'])->on('integration_connections')->restrictOnDelete();
        });

        Schema::create('provider_events', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('integration_connection_id')->constrained()->restrictOnDelete();
            $table->uuid('duplicate_of_id')->nullable();
            $table->string('provider', 40);
            $table->string('environment', 16);
            $table->string('provider_account', 120);
            $table->string('delivery_id')->nullable();
            $table->string('topic', 40)->nullable();
            $table->string('event_type', 80)->nullable();
            $table->string('action', 80)->nullable();
            $table->string('resource_id', 160)->nullable();
            $table->boolean('signature_valid');
            $table->timestampTz('received_at');
            $table->timestampTz('provider_created_at')->nullable();
            $table->string('processing_state', 24)->default('received');
            $table->char('raw_body_checksum', 64);
            $table->text('private_payload')->nullable();
            $table->json('sanitized_headers')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'provider', 'environment', 'delivery_id'], 'provider_events_delivery_unique');
            $table->unique(['tenant_id', 'provider', 'environment', 'raw_body_checksum'], 'provider_events_checksum_unique');
            $table->index(['tenant_id', 'processing_state', 'received_at'], 'provider_events_work_idx');
            $table->foreign(['tenant_id', 'integration_connection_id'], 'provider_events_tenant_connection_fk')->references(['tenant_id', 'id'])->on('integration_connections')->restrictOnDelete();
        });
        Schema::table('provider_events', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'duplicate_of_id'], 'provider_events_tenant_duplicate_fk')
                ->references(['tenant_id', 'id'])->on('provider_events')->restrictOnDelete();
        });

        Schema::create('provider_refunds', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('payment_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('reservation_change_id')->constrained('reservation_changes')->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('environment', 16);
            $table->bigInteger('source_amount_minor');
            $table->char('source_currency', 3);
            $table->bigInteger('charge_amount_minor');
            $table->char('charge_currency', 3);
            $table->uuid('idempotency_key');
            $table->string('provider_payment_id');
            $table->string('provider_refund_id')->nullable();
            $table->string('state', 24)->default('requested');
            $table->char('response_checksum', 64)->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('last_attempted_at')->nullable();
            $table->timestampTz('succeeded_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'provider', 'environment', 'provider_refund_id'], 'provider_refunds_provider_unique');
            $table->index(['tenant_id', 'state', 'updated_at'], 'provider_refunds_work_idx');
            $table->foreign(['tenant_id', 'payment_id'], 'provider_refunds_tenant_payment_fk')->references(['tenant_id', 'id'])->on('payments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reservation_change_id'], 'provider_refunds_tenant_change_fk')->references(['tenant_id', 'id'])->on('reservation_changes')->restrictOnDelete();
        });

        Schema::create('settlement_entries', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('integration_connection_id')->constrained()->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('provider_account', 120);
            $table->string('source_type', 24);
            $table->string('source_id', 160);
            $table->bigInteger('gross_minor')->default(0);
            $table->bigInteger('fee_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('financing_minor')->default(0);
            $table->bigInteger('refunded_minor')->default(0);
            $table->bigInteger('chargeback_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);
            $table->char('currency', 3);
            $table->char('settlement_currency', 3)->nullable();
            $table->date('settlement_date')->nullable();
            $table->string('settlement_status', 32)->nullable();
            $table->char('source_checksum', 64);
            $table->string('reconciliation_state', 24)->default('unmatched');
            $table->text('resolution_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'provider', 'provider_account', 'source_type', 'source_id'], 'settlement_entries_source_unique');
            $table->index(['tenant_id', 'reconciliation_state', 'settlement_date'], 'settlement_entries_work_idx');
            $table->foreign(['tenant_id', 'integration_connection_id'], 'settlement_entries_tenant_connection_fk')->references(['tenant_id', 'id'])->on('integration_connections')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX payment_attempts_one_reusable_idx ON payment_attempts (tenant_id, payment_request_id) WHERE state IN ('creating', 'checkout_ready', 'pending')");
            DB::statement('ALTER TABLE payment_requests ADD CONSTRAINT payment_requests_amount_check CHECK (source_amount_minor > 0)');
            DB::statement("ALTER TABLE payment_requests ADD CONSTRAINT payment_requests_state_check CHECK (state IN ('draft','open','processing','paid','expired','revoked','superseded'))");
            DB::statement('ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_amount_check CHECK (source_amount_minor > 0 AND charge_amount_minor > 0)');
            DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_state_check CHECK (state IN ('creating','checkout_ready','pending','approved','rejected','cancelled','expired','mismatched','failed'))");
            DB::statement('ALTER TABLE provider_refunds ADD CONSTRAINT provider_refunds_amount_check CHECK (source_amount_minor > 0 AND charge_amount_minor > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_entries');
        Schema::dropIfExists('provider_refunds');
        Schema::dropIfExists('provider_events');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payment_requests');
    }

    private function tenantUuid(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
        $table->unique(['tenant_id', 'id']);
    }
};
