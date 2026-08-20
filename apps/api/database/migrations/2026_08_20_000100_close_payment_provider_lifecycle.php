<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropUnique('payment_attempts_external_unique');
            $table->dropUnique('payment_attempts_payment_unique');
            $table->unique(['tenant_id', 'provider', 'environment', 'provider_account', 'external_reference'], 'payment_attempts_external_unique');
            $table->unique(['tenant_id', 'provider', 'environment', 'provider_account', 'provider_payment_id'], 'payment_attempts_payment_unique');
        });
        Schema::table('provider_events', function (Blueprint $table): void {
            $table->dropUnique('provider_events_delivery_unique');
            $table->dropUnique('provider_events_checksum_unique');
            $table->unique(['tenant_id', 'provider', 'environment', 'provider_account', 'delivery_id'], 'provider_events_delivery_unique');
            $table->unique(['tenant_id', 'provider', 'environment', 'provider_account', 'raw_body_checksum'], 'provider_events_checksum_unique');
        });
        Schema::table('provider_refunds', function (Blueprint $table): void {
            $table->foreignUuid('property_id')->nullable()->after('reservation_change_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('integration_connection_id')->nullable()->after('reservation_change_id')->constrained()->restrictOnDelete();
            $table->string('provider_account', 120)->nullable()->after('environment');
            $table->dropUnique('provider_refunds_provider_unique');
        });
        $this->backfillProviderRefundIdentity();
        Schema::table('provider_refunds', function (Blueprint $table): void {
            $table->uuid('property_id')->nullable(false)->change();
            $table->uuid('integration_connection_id')->nullable(false)->change();
            $table->string('provider_account', 120)->nullable(false)->change();
            $table->unique(['tenant_id', 'provider', 'environment', 'provider_account', 'provider_refund_id'], 'provider_refunds_provider_unique');
            $table->foreign(['tenant_id', 'integration_connection_id'], 'provider_refunds_tenant_connection_fk')
                ->references(['tenant_id', 'id'])->on('integration_connections')->restrictOnDelete();
            $table->foreign(['tenant_id', 'property_id'], 'provider_refunds_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('environment', 16)->nullable()->after('provider');
            $table->string('provider_account', 120)->nullable()->after('environment');
            $table->dropUnique('payments_provider_reference_unique');
        });
        $this->backfillPaymentIdentity();
        $this->createPaymentIdentityIndexes();
        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->string('payment_webhook_key', 160)->nullable()->after('secret_reference');
        });
        $this->backfillWebhookKeys();
        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->unique('payment_webhook_key', 'integration_connections_payment_webhook_key_unique');
        });
        Schema::table('settlement_entries', function (Blueprint $table): void {
            $table->foreignUuid('property_id')->nullable()->after('integration_connection_id')->constrained()->restrictOnDelete();
            $table->string('environment', 16)->nullable()->after('provider');
            $table->bigInteger('withholding_minor')->nullable()->after('tax_minor');
            $table->string('settlement_identity', 160)->nullable()->after('settlement_status');
            $table->string('payout_identity', 160)->nullable()->after('settlement_identity');
            $table->date('payout_date')->nullable()->after('payout_identity');
            $table->string('payout_status', 32)->nullable()->after('payout_date');
            $table->foreignId('investigated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('investigated_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->dropUnique('settlement_entries_source_unique');
            $table->bigInteger('tax_minor')->nullable()->default(null)->change();
            $table->bigInteger('financing_minor')->nullable()->default(null)->change();
            $table->bigInteger('refunded_minor')->nullable()->default(null)->change();
            $table->bigInteger('chargeback_minor')->nullable()->default(null)->change();
        });
        $this->backfillSettlementIdentity();
        Schema::table('settlement_entries', function (Blueprint $table): void {
            $table->uuid('property_id')->nullable(false)->change();
            $table->string('environment', 16)->nullable(false)->change();
            $table->unique(['tenant_id', 'provider', 'environment', 'provider_account', 'source_type', 'source_id'], 'settlement_entries_source_unique');
            $table->foreign(['tenant_id', 'property_id'], 'settlement_entries_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
        });

        Schema::create('settlement_entry_revisions', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('settlement_entry_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->bigInteger('gross_minor');
            $table->bigInteger('fee_minor');
            $table->bigInteger('tax_minor')->nullable();
            $table->bigInteger('withholding_minor')->nullable();
            $table->bigInteger('financing_minor')->nullable();
            $table->bigInteger('refunded_minor')->nullable();
            $table->bigInteger('chargeback_minor')->nullable();
            $table->bigInteger('net_minor');
            $table->char('currency', 3);
            $table->char('settlement_currency', 3)->nullable();
            $table->string('settlement_identity', 160)->nullable();
            $table->date('settlement_date')->nullable();
            $table->string('settlement_status', 32)->nullable();
            $table->string('payout_identity', 160)->nullable();
            $table->date('payout_date')->nullable();
            $table->string('payout_status', 32)->nullable();
            $table->char('facts_checksum', 64);
            $table->json('provider_facts');
            $table->timestampTz('recorded_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'settlement_entry_id', 'revision'], 'settlement_revisions_number_unique');
            $table->unique(['tenant_id', 'settlement_entry_id', 'facts_checksum'], 'settlement_revisions_facts_unique');
            $table->foreign(['tenant_id', 'settlement_entry_id'], 'settlement_revisions_tenant_entry_fk')
                ->references(['tenant_id', 'id'])->on('settlement_entries')->restrictOnDelete();
        });
        Schema::create('settlement_variance_actions', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('settlement_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 24);
            $table->text('notes');
            $table->timestampTz('acted_at');
            $table->timestamps();
            $table->index(['tenant_id', 'settlement_entry_id', 'acted_at'], 'settlement_variance_actions_entry_idx');
            $table->foreign(['tenant_id', 'settlement_entry_id'], 'settlement_actions_tenant_entry_fk')
                ->references(['tenant_id', 'id'])->on('settlement_entries')->restrictOnDelete();
        });
        Schema::create('settlement_report_imports', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('integration_connection_id')->constrained()->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('environment', 16);
            $table->string('provider_account', 120);
            $table->string('report_type', 32);
            $table->string('provider_report_id', 160);
            $table->unsignedInteger('revision');
            $table->string('file_name');
            $table->char('file_checksum', 64);
            $table->json('report_metadata');
            $table->boolean('is_fixture')->default(false);
            $table->unsignedInteger('row_count')->default(0);
            $table->timestampTz('imported_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'provider', 'environment', 'provider_account', 'report_type', 'provider_report_id', 'revision'], 'settlement_report_imports_revision_unique');
            $table->unique(['tenant_id', 'provider', 'environment', 'provider_account', 'report_type', 'provider_report_id', 'file_checksum'], 'settlement_report_imports_checksum_unique');
            $table->foreign(['tenant_id', 'integration_connection_id'], 'settlement_imports_tenant_connection_fk')->references(['tenant_id', 'id'])->on('integration_connections')->restrictOnDelete();
        });
        Schema::create('settlement_report_rows', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('settlement_report_import_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('payment_attempt_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('property_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('row_identity', 160);
            $table->unsignedInteger('occurrence');
            $table->string('source_id', 160)->nullable();
            $table->string('external_reference', 160)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('row_kind', 48);
            $table->string('application_state', 24);
            $table->char('canonical_checksum', 64);
            $table->json('canonical_row');
            $table->timestampTz('recorded_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'settlement_report_import_id', 'row_identity'], 'settlement_report_rows_identity_unique');
            $table->index(['tenant_id', 'source_id']);
            $table->foreign(['tenant_id', 'settlement_report_import_id'], 'settlement_rows_tenant_import_fk')->references(['tenant_id', 'id'])->on('settlement_report_imports')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_attempt_id'], 'settlement_rows_tenant_attempt_fk')->references(['tenant_id', 'id'])->on('payment_attempts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'property_id'], 'settlement_rows_tenant_property_fk')->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
        });

        Schema::create('provider_disputes', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('reservation_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('payment_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('payment_attempt_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('integration_connection_id')->constrained()->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('environment', 16);
            $table->string('provider_account', 120);
            $table->string('provider_dispute_id', 160);
            $table->string('provider_payment_id', 160);
            $table->string('state', 24);
            $table->string('status_detail', 80)->nullable();
            $table->string('impact_state', 24)->default('none');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('reason', 160)->nullable();
            $table->boolean('coverage_applied')->nullable();
            $table->boolean('documentation_required')->nullable();
            $table->timestampTz('documentation_deadline')->nullable();
            $table->timestampTz('provider_created_at')->nullable();
            $table->timestampTz('provider_updated_at')->nullable();
            $table->timestampTz('last_checked_at');
            $table->unsignedInteger('current_revision')->default(0);
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'provider', 'environment', 'provider_account', 'provider_dispute_id'], 'provider_disputes_identity_unique');
            $table->index(['tenant_id', 'property_id', 'state', 'updated_at'], 'provider_disputes_finance_idx');
            $table->foreign(['tenant_id', 'property_id'], 'provider_disputes_tenant_property_fk')->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reservation_id'], 'provider_disputes_tenant_reservation_fk')->references(['tenant_id', 'id'])->on('reservations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_id'], 'provider_disputes_tenant_payment_fk')->references(['tenant_id', 'id'])->on('payments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_attempt_id'], 'provider_disputes_tenant_attempt_fk')->references(['tenant_id', 'id'])->on('payment_attempts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'integration_connection_id'], 'provider_disputes_tenant_connection_fk')->references(['tenant_id', 'id'])->on('integration_connections')->restrictOnDelete();
        });
        Schema::create('provider_dispute_revisions', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('provider_dispute_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('provider_event_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('status', 48);
            $table->string('status_detail', 80)->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->boolean('coverage_applied')->nullable();
            $table->boolean('documentation_required')->nullable();
            $table->string('reason', 160)->nullable();
            $table->timestampTz('documentation_deadline')->nullable();
            $table->timestampTz('provider_created_at')->nullable();
            $table->timestampTz('provider_updated_at')->nullable();
            $table->json('provider_facts');
            $table->char('facts_checksum', 64);
            $table->timestampTz('recorded_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'provider_dispute_id', 'revision'], 'provider_dispute_revisions_number_unique');
            $table->unique(['tenant_id', 'provider_dispute_id', 'facts_checksum'], 'provider_dispute_revisions_facts_unique');
            $table->foreign(['tenant_id', 'provider_dispute_id'], 'dispute_revisions_tenant_dispute_fk')->references(['tenant_id', 'id'])->on('provider_disputes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'provider_event_id'], 'dispute_revisions_tenant_event_fk')->references(['tenant_id', 'id'])->on('provider_events')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_provider_origin_check');
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_provider_origin_check CHECK (origin = 'manual' OR (provider IS NOT NULL AND environment IS NOT NULL AND provider_account IS NOT NULL AND provider_reference IS NOT NULL))");
            DB::statement("ALTER TABLE provider_disputes ADD CONSTRAINT provider_disputes_state_check CHECK (state IN ('open','under_review','won','lost','unknown'))");
            DB::statement("ALTER TABLE provider_disputes ADD CONSTRAINT provider_disputes_impact_check CHECK (impact_state IN ('none','pending_finance','applied','reversed'))");
            DB::statement('ALTER TABLE provider_disputes ADD CONSTRAINT provider_disputes_amount_check CHECK (amount_minor > 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_provider_origin_check');
        }
        Schema::dropIfExists('provider_dispute_revisions');
        Schema::dropIfExists('provider_disputes');
        Schema::dropIfExists('settlement_report_rows');
        Schema::dropIfExists('settlement_report_imports');
        Schema::dropIfExists('settlement_variance_actions');
        Schema::dropIfExists('settlement_entry_revisions');
        if (DB::getDriverName() === 'sqlite') {
            // SQLite test-suite teardown immediately rolls back every older migration and
            // cannot drop named foreign keys. The production PostgreSQL rollback below
            // performs the full in-place restoration and is covered against existing data.
            $this->dropPaymentIdentityIndexes();

            return;
        }

        DB::table('settlement_entries')->whereNull('tax_minor')->update(['tax_minor' => 0]);
        DB::table('settlement_entries')->whereNull('financing_minor')->update(['financing_minor' => 0]);
        DB::table('settlement_entries')->whereNull('refunded_minor')->update(['refunded_minor' => 0]);
        DB::table('settlement_entries')->whereNull('chargeback_minor')->update(['chargeback_minor' => 0]);
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('settlement_entries', function (Blueprint $table): void {
                $table->dropForeign('settlement_entries_tenant_property_fk');
                $table->dropForeign(['property_id']);
                $table->dropForeign(['investigated_by']);
                $table->dropForeign(['resolved_by']);
            });
        }
        Schema::table('settlement_entries', function (Blueprint $table): void {
            $table->dropUnique('settlement_entries_source_unique');
            $table->unique(['tenant_id', 'provider', 'provider_account', 'source_type', 'source_id'], 'settlement_entries_source_unique');
            $table->dropColumn([
                'property_id', 'environment', 'withholding_minor', 'settlement_identity', 'payout_identity',
                'payout_date', 'payout_status', 'investigated_by', 'investigated_at', 'resolved_by', 'resolved_at',
            ]);
            $table->bigInteger('tax_minor')->nullable(false)->default(0)->change();
            $table->bigInteger('financing_minor')->nullable(false)->default(0)->change();
            $table->bigInteger('refunded_minor')->nullable(false)->default(0)->change();
            $table->bigInteger('chargeback_minor')->nullable(false)->default(0)->change();
        });
        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->dropUnique('integration_connections_payment_webhook_key_unique');
            $table->dropColumn('payment_webhook_key');
        });
        $this->dropPaymentIdentityIndexes();
        Schema::table('payments', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'provider', 'provider_reference'], 'payments_provider_reference_unique');
            $table->dropColumn(['environment', 'provider_account']);
        });
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('provider_refunds', function (Blueprint $table): void {
                $table->dropForeign('provider_refunds_tenant_connection_fk');
                $table->dropForeign('provider_refunds_tenant_property_fk');
                $table->dropForeign(['integration_connection_id']);
                $table->dropForeign(['property_id']);
            });
        }
        Schema::table('provider_refunds', function (Blueprint $table): void {
            $table->dropUnique('provider_refunds_provider_unique');
            $table->unique(['tenant_id', 'provider', 'environment', 'provider_refund_id'], 'provider_refunds_provider_unique');
            $table->dropColumn(['integration_connection_id', 'property_id', 'provider_account']);
        });
        Schema::table('provider_events', function (Blueprint $table): void {
            $table->dropUnique('provider_events_delivery_unique');
            $table->dropUnique('provider_events_checksum_unique');
            $table->unique(['tenant_id', 'provider', 'environment', 'delivery_id'], 'provider_events_delivery_unique');
            $table->unique(['tenant_id', 'provider', 'environment', 'raw_body_checksum'], 'provider_events_checksum_unique');
        });
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropUnique('payment_attempts_external_unique');
            $table->dropUnique('payment_attempts_payment_unique');
            $table->unique(['tenant_id', 'provider', 'environment', 'external_reference'], 'payment_attempts_external_unique');
            $table->unique(['tenant_id', 'provider', 'environment', 'provider_payment_id'], 'payment_attempts_payment_unique');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_provider_origin_check CHECK (origin = 'manual' OR (provider IS NOT NULL AND provider_reference IS NOT NULL))");
        }
    }

    private function backfillProviderRefundIdentity(): void
    {
        DB::statement('UPDATE provider_refunds SET property_id = (SELECT reservations.property_id FROM payments JOIN reservations ON reservations.id = payments.reservation_id AND reservations.tenant_id = payments.tenant_id WHERE payments.id = provider_refunds.payment_id AND payments.tenant_id = provider_refunds.tenant_id LIMIT 1) WHERE property_id IS NULL');
        DB::statement('UPDATE provider_refunds SET integration_connection_id = (SELECT payment_attempts.integration_connection_id FROM payment_attempts WHERE payment_attempts.tenant_id = provider_refunds.tenant_id AND payment_attempts.provider = provider_refunds.provider AND payment_attempts.environment = provider_refunds.environment AND payment_attempts.provider_payment_id = provider_refunds.provider_payment_id ORDER BY payment_attempts.created_at LIMIT 1) WHERE integration_connection_id IS NULL');
        DB::statement('UPDATE provider_refunds SET provider_account = (SELECT payment_attempts.provider_account FROM payment_attempts WHERE payment_attempts.tenant_id = provider_refunds.tenant_id AND payment_attempts.provider = provider_refunds.provider AND payment_attempts.environment = provider_refunds.environment AND payment_attempts.provider_payment_id = provider_refunds.provider_payment_id ORDER BY payment_attempts.created_at LIMIT 1) WHERE provider_account IS NULL');
    }

    private function backfillPaymentIdentity(): void
    {
        DB::statement("UPDATE payments SET environment = (SELECT payment_attempts.environment FROM payment_attempts WHERE payment_attempts.tenant_id = payments.tenant_id AND payment_attempts.reservation_id = payments.reservation_id AND payment_attempts.provider = payments.provider AND payment_attempts.provider_payment_id = payments.provider_reference ORDER BY payment_attempts.created_at LIMIT 1), provider_account = (SELECT payment_attempts.provider_account FROM payment_attempts WHERE payment_attempts.tenant_id = payments.tenant_id AND payment_attempts.reservation_id = payments.reservation_id AND payment_attempts.provider = payments.provider AND payment_attempts.provider_payment_id = payments.provider_reference ORDER BY payment_attempts.created_at LIMIT 1) WHERE origin = 'provider'");
    }

    private function backfillSettlementIdentity(): void
    {
        DB::statement("UPDATE settlement_entries SET property_id = (SELECT payment_attempts.property_id FROM payment_attempts WHERE payment_attempts.tenant_id = settlement_entries.tenant_id AND payment_attempts.provider = settlement_entries.provider AND payment_attempts.provider_account = settlement_entries.provider_account AND payment_attempts.provider_payment_id = settlement_entries.source_id ORDER BY payment_attempts.created_at LIMIT 1) WHERE property_id IS NULL AND source_type = 'payment'");
        DB::statement("UPDATE settlement_entries SET environment = (SELECT payment_attempts.environment FROM payment_attempts WHERE payment_attempts.tenant_id = settlement_entries.tenant_id AND payment_attempts.provider = settlement_entries.provider AND payment_attempts.provider_account = settlement_entries.provider_account AND payment_attempts.provider_payment_id = settlement_entries.source_id ORDER BY payment_attempts.created_at LIMIT 1) WHERE environment IS NULL AND source_type = 'payment'");
    }

    private function backfillWebhookKeys(): void
    {
        foreach (DB::table('integration_connections')->where('type', 'payment')->get(['id', 'configuration']) as $connection) {
            $configuration = is_string($connection->configuration) ? json_decode($connection->configuration, true) : $connection->configuration;
            $key = is_array($configuration) ? ($configuration['webhook_key'] ?? null) : null;
            if ((! is_string($key) || $key === '') && Schema::hasTable('integration_endpoint_keys')) {
                $key = DB::table('integration_endpoint_keys')
                    ->where('integration_connection_id', $connection->id)
                    ->orderByDesc('version')
                    ->value('key_hash');
            }
            if (is_string($key) && $key !== '') {
                DB::table('integration_connections')->where('id', $connection->id)->update(['payment_webhook_key' => $key]);
            }
        }
    }

    private function createPaymentIdentityIndexes(): void
    {
        DB::statement("CREATE UNIQUE INDEX payments_provider_reference_unique ON payments (tenant_id, provider, provider_reference) WHERE origin = 'manual' AND provider IS NOT NULL AND provider_reference IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX payments_provider_identity_unique ON payments (tenant_id, provider, environment, provider_account, provider_reference) WHERE origin = 'provider'");
    }

    private function dropPaymentIdentityIndexes(): void
    {
        DB::statement('DROP INDEX IF EXISTS payments_provider_reference_unique');
        DB::statement('DROP INDEX IF EXISTS payments_provider_identity_unique');
    }

    private function tenantUuid(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
        $table->unique(['tenant_id', 'id']);
    }
};
