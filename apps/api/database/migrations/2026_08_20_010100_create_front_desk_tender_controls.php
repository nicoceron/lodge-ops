<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $bad = $this->paymentClassificationExceptions();
        if ($bad !== []) {
            throw new RuntimeException('P3-06B payment classification exceptions: '.implode(', ', $bad));
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->string('channel', 32)->nullable()->after('method');
            $table->string('entry_mode', 24)->nullable()->after('channel');
            $table->index(['tenant_id', 'channel', 'status'], 'payments_channel_status_idx');
        });

        $this->backfillPaymentClassification();

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('payments', function (Blueprint $table): void {
                $table->string('channel', 32)->nullable(false)->change();
                $table->string('entry_mode', 24)->nullable(false)->change();
            });
        }

        Schema::create('payment_tender_details', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('reservation_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('deposit_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('channel', 32);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('processor_alias', 80)->default('manual');
            $table->string('merchant_account_alias', 120)->default('manual');
            $table->string('terminal_identifier', 80)->default('manual');
            $table->string('transaction_reference', 160)->nullable();
            $table->string('authorization_reference', 160)->nullable();
            $table->string('batch_reference', 120)->nullable();
            $table->string('card_brand', 40)->nullable();
            $table->char('card_last_four', 4)->nullable();
            $table->text('note')->nullable();
            $table->string('state', 32)->default('posted');
            $table->foreignUuid('duplicate_of_id')->nullable();
            $table->text('review_reason')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('received_at');
            $table->date('business_date');
            $table->string('command_key', 160);
            $table->char('command_checksum', 64);
            $table->timestamps();
            $table->unique(['tenant_id', 'payment_id'], 'payment_tender_details_payment_unique');
            $table->unique(['tenant_id', 'command_key'], 'payment_tender_details_command_unique');
            $table->index(['tenant_id', 'property_id', 'state'], 'payment_tender_details_review_idx');
            $table->foreign(['tenant_id', 'property_id'], 'payment_tender_details_tenant_property_fk')->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reservation_id'], 'payment_tender_details_tenant_reservation_fk')->references(['tenant_id', 'id'])->on('reservations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_id'], 'payment_tender_details_tenant_payment_fk')->references(['tenant_id', 'id'])->on('payments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'deposit_id'], 'payment_tender_details_tenant_deposit_fk')->references(['tenant_id', 'id'])->on('deposits')->restrictOnDelete();
            $table->foreign(['tenant_id', 'duplicate_of_id'], 'payment_tender_details_duplicate_fk')->references(['tenant_id', 'id'])->on('payment_tender_details')->restrictOnDelete();
        });

        Schema::create('cash_shifts', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->string('currency', 3);
            $table->string('state', 32)->default('open');
            $table->bigInteger('opening_float_minor');
            $table->bigInteger('expected_cash_minor')->nullable();
            $table->bigInteger('counted_cash_minor')->nullable();
            $table->bigInteger('variance_minor')->nullable();
            $table->date('business_date');
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('close_reason')->nullable();
            $table->char('calculation_checksum', 64)->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('approval_reason')->nullable();
            $table->string('command_key', 160);
            $table->char('command_checksum', 64);
            $table->timestamps();
            $table->unique(['tenant_id', 'command_key'], 'cash_shifts_command_unique');
            $table->index(['tenant_id', 'property_id', 'state'], 'cash_shifts_property_state_idx');
            $table->foreign(['tenant_id', 'property_id'], 'cash_shifts_tenant_property_fk')->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
        });

        Schema::create('cash_shift_movements', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('cash_shift_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('refund_change_id')->nullable()->constrained('reservation_changes')->restrictOnDelete();
            $table->foreignUuid('reverses_movement_id')->nullable();
            $table->string('type', 32);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->text('reason')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at');
            $table->string('command_key', 160);
            $table->char('command_checksum', 64);
            $table->timestamps();
            $table->unique(['tenant_id', 'command_key'], 'cash_shift_movements_command_unique');
            $table->unique(['tenant_id', 'payment_id', 'type'], 'cash_shift_movements_payment_type_unique');
            $table->unique(['tenant_id', 'refund_change_id', 'type'], 'cash_shift_movements_refund_type_unique');
            $table->unique(['tenant_id', 'reverses_movement_id'], 'cash_shift_movements_reversal_unique');
            $table->foreign(['tenant_id', 'property_id'], 'cash_shift_movements_tenant_property_fk')->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
            $table->foreign(['tenant_id', 'cash_shift_id'], 'cash_shift_movements_tenant_shift_fk')->references(['tenant_id', 'id'])->on('cash_shifts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_id'], 'cash_shift_movements_tenant_payment_fk')->references(['tenant_id', 'id'])->on('payments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'refund_change_id'], 'cash_shift_movements_tenant_refund_fk')->references(['tenant_id', 'id'])->on('reservation_changes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reverses_movement_id'], 'cash_shift_movements_reversal_fk')->references(['tenant_id', 'id'])->on('cash_shift_movements')->restrictOnDelete();
        });

        Schema::create('financial_command_records', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->string('command_type', 100);
            $table->string('idempotency_key', 160);
            $table->char('request_checksum', 64);
            $table->string('result_model', 180);
            $table->string('result_id', 64);
            $table->timestamps();
            $table->unique(['tenant_id', 'command_type', 'idempotency_key'], 'financial_commands_identity_unique');
        });

        Schema::table('guest_payment_evidence', function (Blueprint $table): void {
            $table->uuid('refund_change_id')->nullable()->after('payment_id');
            $table->uuid('tender_detail_id')->nullable()->after('refund_change_id');
            $table->string('disk', 40)->default('local')->after('storage_path');
            $table->string('storage_key')->nullable()->after('disk');
            $table->string('original_name')->nullable()->after('file_name');
            $table->string('detected_mime', 100)->nullable()->after('content_type');
            $table->string('scan_state', 24)->default('accepted')->after('scan_status');
            $table->unsignedBigInteger('uploaded_by')->nullable()->after('reviewed_by');
            $table->timestampTz('scanned_at')->nullable()->after('submitted_at');
        });
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('guest_payment_evidence', function (Blueprint $table): void {
                $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
                $table->foreign('refund_change_id')->references('id')->on('reservation_changes')->nullOnDelete();
                $table->foreign('tender_detail_id')->references('id')->on('payment_tender_details')->nullOnDelete();
                $table->foreign(['tenant_id', 'refund_change_id'], 'guest_evidence_tenant_refund_fk')->references(['tenant_id', 'id'])->on('reservation_changes')->nullOnDelete();
                $table->foreign(['tenant_id', 'tender_detail_id'], 'guest_evidence_tenant_tender_fk')->references(['tenant_id', 'id'])->on('payment_tender_details')->nullOnDelete();
            });
        }
        DB::table('guest_payment_evidence')->update([
            'storage_key' => DB::raw('storage_path'),
            'original_name' => DB::raw('file_name'),
            'detected_mime' => DB::raw('content_type'),
            'scan_state' => DB::raw('scan_status'),
        ]);

        $this->createChecksAndIndexes();
    }

    private function backfillPaymentClassification(): void
    {
        DB::table('payments')->where('origin', 'provider')->update(['channel' => 'online_checkout', 'entry_mode' => 'provider_reported']);
        foreach ([
            'bank_transfer' => 'bank_transfer',
            'cash' => 'cash',
            'card' => 'external_terminal',
            'other' => 'manual_other',
            'manual' => 'manual_other',
        ] as $method => $channel) {
            DB::table('payments')->where('origin', 'manual')->where('method', $method)->update(['channel' => $channel, 'entry_mode' => 'staff_recorded']);
        }
    }

    /** @return list<string> */
    private function paymentClassificationExceptions(): array
    {
        return DB::table('payments')->where(function ($query): void {
            $query->whereNull('origin')
                ->orWhereNotIn('origin', ['manual', 'provider'])
                ->orWhereNull('method')
                ->orWhereNotIn('method', ['bank_transfer', 'cash', 'card', 'other', 'manual', 'provider', 'mercado_pago_checkout_pro'])
                ->orWhere(function ($provider): void {
                    $provider->where('origin', 'provider')->where(function ($identity): void {
                        $identity->whereNull('provider')->orWhereNull('provider_reference')->orWhereNull('provider_account')->orWhereNull('environment')
                            ->orWhereNotIn('method', ['card', 'provider', 'mercado_pago_checkout_pro']);
                    });
                })
                ->orWhere(function ($manual): void {
                    $manual->where('origin', 'manual')->where(function ($contradiction): void {
                        $contradiction->whereIn('method', ['provider', 'mercado_pago_checkout_pro'])
                            ->orWhereNotNull('provider')->orWhereNotNull('provider_reference')
                            ->orWhereNotNull('provider_account')->orWhereNotNull('environment');
                    });
                });
        })->orderBy('id')->pluck('id')->all();
    }

    private function createChecksAndIndexes(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $valid = "((NEW.origin = 'manual' AND NEW.entry_mode = 'staff_recorded' AND NEW.provider IS NULL AND NEW.provider_reference IS NULL AND NEW.provider_account IS NULL AND NEW.environment IS NULL AND ((NEW.channel = 'bank_transfer' AND NEW.method = 'bank_transfer') OR (NEW.channel = 'cash' AND NEW.method = 'cash') OR (NEW.channel = 'external_terminal' AND NEW.method = 'card') OR (NEW.channel = 'manual_other' AND NEW.method IN ('other','manual')))) OR (NEW.origin = 'provider' AND NEW.entry_mode = 'provider_reported' AND NEW.provider IS NOT NULL AND NEW.provider_reference IS NOT NULL AND NEW.provider_account IS NOT NULL AND NEW.environment IS NOT NULL AND NEW.method IN ('card','provider','mercado_pago_checkout_pro') AND NEW.channel IN ('online_checkout','integrated_terminal','qr')))";
            $invalid = "NEW.origin IS NULL OR NEW.method IS NULL OR NEW.channel IS NULL OR NEW.entry_mode IS NULL OR NOT ({$valid})";
            DB::statement("CREATE TRIGGER payments_classification_insert BEFORE INSERT ON payments WHEN {$invalid} BEGIN SELECT RAISE(ABORT, 'invalid payment classification'); END");
            DB::statement("CREATE TRIGGER payments_classification_update BEFORE UPDATE OF origin, method, channel, entry_mode, provider, provider_reference, provider_account, environment ON payments WHEN {$invalid} BEGIN SELECT RAISE(ABORT, 'invalid payment classification'); END");

            return;
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_channel_check CHECK (channel IN ('bank_transfer','cash','external_terminal','manual_other','online_checkout','integrated_terminal','qr'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_entry_mode_check CHECK (entry_mode IN ('staff_recorded','provider_reported'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_channel_origin_check CHECK ((origin = 'manual' AND entry_mode = 'staff_recorded' AND channel IN ('bank_transfer','cash','external_terminal','manual_other')) OR (origin = 'provider' AND entry_mode = 'provider_reported' AND channel IN ('online_checkout','integrated_terminal','qr')))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_legacy_method_channel_check CHECK ((channel = 'bank_transfer' AND method = 'bank_transfer') OR (channel = 'cash' AND method = 'cash') OR (channel = 'external_terminal' AND method = 'card') OR (channel = 'manual_other' AND method IN ('other','manual')) OR (channel IN ('online_checkout','integrated_terminal','qr') AND method IN ('card','provider','mercado_pago_checkout_pro')))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_provider_identity_check CHECK ((origin = 'manual' AND provider IS NULL AND provider_reference IS NULL AND provider_account IS NULL AND environment IS NULL) OR (origin = 'provider' AND provider IS NOT NULL AND provider_reference IS NOT NULL AND provider_account IS NOT NULL AND environment IS NOT NULL))");
        DB::statement("ALTER TABLE payment_tender_details ADD CONSTRAINT payment_tender_last_four_check CHECK (card_last_four IS NULL OR card_last_four ~ '^[0-9]{4}$')");
        DB::statement("ALTER TABLE payment_tender_details ADD CONSTRAINT payment_tender_posted_identity_check CHECK (state <> 'posted' OR payment_id IS NOT NULL)");
        DB::statement("CREATE UNIQUE INDEX payment_tender_external_identity_unique ON payment_tender_details (tenant_id, property_id, merchant_account_alias, processor_alias, terminal_identifier, transaction_reference) WHERE channel = 'external_terminal' AND state = 'posted' AND transaction_reference IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX cash_shifts_one_open_unique ON cash_shifts (tenant_id, property_id, cashier_id, currency) WHERE state = 'open'");
        DB::statement("ALTER TABLE cash_shift_movements ADD CONSTRAINT cash_shift_movement_nonzero_check CHECK (amount_minor <> 0 OR type = 'opening_float')");
    }

    public function down(): void
    {
        $this->refuseOperationalFactLoss();
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS payments_classification_update');
            DB::statement('DROP TRIGGER IF EXISTS payments_classification_insert');
            Schema::table('guest_payment_evidence', function (Blueprint $table): void {
                $table->dropColumn(['refund_change_id', 'tender_detail_id', 'disk', 'storage_key', 'original_name', 'detected_mime', 'scan_state', 'uploaded_by', 'scanned_at']);
            });
            Schema::dropIfExists('financial_command_records');
            Schema::dropIfExists('cash_shift_movements');
            Schema::dropIfExists('cash_shifts');
            Schema::dropIfExists('payment_tender_details');
            Schema::table('payments', function (Blueprint $table): void {
                $table->dropIndex('payments_channel_status_idx');
                $table->dropColumn(['channel', 'entry_mode']);
            });

            return;
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS cash_shifts_one_open_unique');
            DB::statement('DROP INDEX IF EXISTS payment_tender_external_identity_unique');
            DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_legacy_method_channel_check');
            DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_provider_identity_check');
            DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_channel_origin_check');
            DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_entry_mode_check');
            DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_channel_check');
        }

        Schema::table('guest_payment_evidence', function (Blueprint $table): void {
            $table->dropForeign('guest_evidence_tenant_tender_fk');
            $table->dropForeign('guest_evidence_tenant_refund_fk');
            $table->dropForeign(['tender_detail_id']);
            $table->dropForeign(['refund_change_id']);
            $table->dropForeign(['uploaded_by']);
            $table->dropColumn(['refund_change_id', 'tender_detail_id', 'disk', 'storage_key', 'original_name', 'detected_mime', 'scan_state', 'uploaded_by', 'scanned_at']);
        });
        Schema::dropIfExists('financial_command_records');
        Schema::dropIfExists('cash_shift_movements');
        Schema::dropIfExists('cash_shifts');
        Schema::dropIfExists('payment_tender_details');
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('payments_channel_status_idx');
            $table->dropColumn(['channel', 'entry_mode']);
        });
    }

    private function refuseOperationalFactLoss(): void
    {
        if ((bool) config('front_desk_tenders.allow_operational_fact_rollback', false)) {
            return;
        }
        foreach (['payment_tender_details', 'cash_shifts', 'cash_shift_movements', 'financial_command_records'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("P3-06B rollback refused: {$table} contains operational facts that cannot be discarded.");
            }
        }
        if (Schema::hasColumn('guest_payment_evidence', 'refund_change_id')
            && DB::table('guest_payment_evidence')->where(function ($query): void {
                $query->whereNotNull('refund_change_id')->orWhereNotNull('tender_detail_id')->orWhereNotNull('uploaded_by');
            })->exists()) {
            throw new RuntimeException('P3-06B rollback refused: payment evidence contains tender/refund operational facts.');
        }
    }

    private function tenantUuid(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
        $table->unique(['tenant_id', 'id']);
    }
};
