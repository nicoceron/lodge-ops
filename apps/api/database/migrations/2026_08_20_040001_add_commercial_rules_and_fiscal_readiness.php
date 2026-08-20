<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_plans', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1)->after('name');
            $table->string('state', 20)->default('draft')->after('version');
            $table->foreignUuid('supersedes_id')->nullable()->after('state')->constrained('rate_plans')->nullOnDelete();
            $table->timestampTz('published_at')->nullable()->after('is_active');
            $table->timestampTz('retired_at')->nullable()->after('published_at');
            $table->foreignId('approved_by')->nullable()->after('retired_at')->constrained('users')->nullOnDelete();
            $table->dropUnique(['tenant_id', 'property_id', 'name', 'currency']);
            $table->unique(['tenant_id', 'property_id', 'name', 'currency', 'version'], 'rate_plans_version_unique');
        });

        Schema::table('rate_rules', function (Blueprint $table): void {
            $table->string('name')->default('Nightly rate')->after('resource_category_id');
            $table->foreignUuid('program_id')->nullable()->after('resource_category_id')->constrained()->nullOnDelete();
            $table->unsignedInteger('version')->default(1)->after('name');
            $table->string('component_type', 30)->default('base')->after('version');
            $table->unsignedSmallInteger('minimum_advance_days')->nullable()->after('maximum_stay');
            $table->unsignedSmallInteger('maximum_advance_days')->nullable()->after('minimum_advance_days');
            $table->json('allowed_arrival_days')->nullable()->after('maximum_advance_days');
            $table->boolean('blackout')->default(false)->after('allowed_arrival_days');
            $table->unsignedSmallInteger('minimum_occupancy')->nullable()->after('blackout');
            $table->unsignedSmallInteger('maximum_occupancy')->nullable()->after('minimum_occupancy');
            $table->boolean('buyout_only')->default(false)->after('maximum_occupancy');
            $table->unsignedBigInteger('adult_amount_minor')->nullable()->after('amount_minor');
            $table->unsignedBigInteger('child_amount_minor')->nullable()->after('adult_amount_minor');
            $table->unsignedBigInteger('infant_amount_minor')->nullable()->after('child_amount_minor');
            $table->unsignedBigInteger('single_supplement_minor')->default(0)->after('infant_amount_minor');
            $table->json('group_tiers')->nullable()->after('single_supplement_minor');
            $table->integer('length_of_stay_adjustment_basis_points')->default(0)->after('group_tiers');
        });

        Schema::table('tax_rules', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1)->after('name');
            $table->string('state', 20)->default('draft')->after('version');
            $table->foreignUuid('supersedes_id')->nullable()->after('state');
            $table->string('taxable_discount_allocation', 32)->default('before_tax')->after('is_inclusive');
            $table->string('rounding_mode', 20)->default('half_up')->after('taxable_discount_allocation');
            $table->string('rounding_scope', 20)->default('total')->after('rounding_mode');
            $table->json('jurisdiction_inputs')->nullable()->after('rounding_scope');
            $table->timestampTz('published_at')->nullable()->after('is_active');
            $table->timestampTz('retired_at')->nullable()->after('published_at');
            $table->foreignId('approved_by')->nullable()->after('retired_at')->constrained('users')->nullOnDelete();
            $table->dropUnique(['tenant_id', 'property_id', 'name']);
            $table->unique(['tenant_id', 'property_id', 'name', 'version'], 'tax_rules_version_unique');
        });
        Schema::table('tax_rules', function (Blueprint $table): void {
            $table->foreign('supersedes_id', 'tax_rules_supersedes_id_foreign')->references('id')->on('tax_rules')->nullOnDelete();
        });

        Schema::table('booking_quotes', function (Blueprint $table): void {
            $table->unsignedSmallInteger('infants')->default(0)->after('children');
            $table->bigInteger('discount_minor')->default(0)->after('subtotal_minor');
            $table->json('calculation_snapshot')->nullable()->after('cancellation_policy_snapshot');
        });

        Schema::table('reservations', function (Blueprint $table): void {
            $table->unsignedSmallInteger('infants')->default(0)->after('children');
        });

        Schema::table('booking_quote_lines', function (Blueprint $table): void {
            $table->string('basis', 32)->default('per_stay')->after('description');
            $table->unsignedSmallInteger('calculation_order')->default(0)->after('basis');
            $table->bigInteger('pre_total_minor')->default(0)->after('unit_amount_minor');
            $table->bigInteger('post_total_minor')->default(0)->after('gross_amount_minor');
            $table->string('rounding_mode', 20)->default('none')->after('post_total_minor');
            $table->text('explanation')->nullable()->after('rounding_mode');
        });

        Schema::create('rate_plan_services', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('rate_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('catalog_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('selection_type', 16)->default('optional');
            $table->string('quantity_basis', 20)->default('per_stay');
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->unsignedSmallInteger('default_quantity')->default(1);
            $table->unsignedSmallInteger('maximum_quantity')->default(1);
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'rate_plan_id', 'catalog_item_id', 'version'], 'rate_plan_services_version_unique');
            $table->foreign(['tenant_id', 'rate_plan_id'], 'rate_plan_services_tenant_plan_fk')
                ->references(['tenant_id', 'id'])->on('rate_plans')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'catalog_item_id'], 'rate_plan_services_tenant_item_fk')
                ->references(['tenant_id', 'id'])->on('catalog_items')->restrictOnDelete();
        });

        Schema::create('commercial_promotions', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('supersedes_id')->nullable();
            $table->foreignId('approval_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('public_label');
            $table->unsignedInteger('version')->default(1);
            $table->string('state', 20)->default('draft');
            $table->char('currency', 3);
            $table->string('discount_type', 20)->default('percentage');
            $table->unsignedInteger('percentage_basis_points')->nullable();
            $table->unsignedBigInteger('fixed_amount_minor')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->json('applicability')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_guest_limit')->nullable();
            $table->unsignedInteger('per_session_limit')->nullable();
            $table->unsignedBigInteger('budget_minor')->nullable();
            $table->boolean('requires_code')->default(false);
            $table->boolean('exclusive')->default(false);
            $table->string('stacking_group', 80)->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('reinstate_on_cancel')->default(false);
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'property_id', 'state', 'valid_from', 'valid_until'], 'commercial_promotions_lookup_idx');
            $table->foreign(['tenant_id', 'property_id'], 'commercial_promotions_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
        });
        Schema::table('commercial_promotions', function (Blueprint $table): void {
            $table->foreign('supersedes_id', 'commercial_promotions_supersedes_id_foreign')
                ->references('id')->on('commercial_promotions')->nullOnDelete();
        });

        Schema::create('vouchers', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('commercial_promotion_id')->constrained()->restrictOnDelete();
            $table->char('code_hash', 64);
            $table->string('public_label');
            $table->string('state', 20)->default('active');
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_guest_limit')->nullable();
            $table->unsignedInteger('per_session_limit')->nullable();
            $table->unsignedBigInteger('budget_minor')->nullable();
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_until')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code_hash'], 'vouchers_tenant_hash_unique');
            $table->index(['tenant_id', 'property_id', 'state'], 'vouchers_lookup_idx');
            $table->foreign(['tenant_id', 'property_id'], 'vouchers_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'commercial_promotion_id'], 'vouchers_tenant_promotion_fk')
                ->references(['tenant_id', 'id'])->on('commercial_promotions')->restrictOnDelete();
        });

        Schema::create('voucher_redemptions', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('voucher_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('booking_quote_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('reservation_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->char('session_key_hash', 64)->nullable();
            $table->uuid('command_id');
            $table->string('state', 20)->default('reserved');
            $table->char('currency', 3);
            $table->unsignedBigInteger('discount_minor');
            $table->timestampTz('reserved_at');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'voucher_id', 'command_id'], 'voucher_redemptions_command_unique');
            $table->unique(['tenant_id', 'booking_quote_id'], 'voucher_redemptions_quote_unique');
            $table->index(['tenant_id', 'voucher_id', 'session_key_hash'], 'voucher_redemptions_session_idx');
            $table->foreign(['tenant_id', 'voucher_id'], 'voucher_redemptions_tenant_voucher_fk')
                ->references(['tenant_id', 'id'])->on('vouchers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'booking_quote_id'], 'voucher_redemptions_tenant_quote_fk')
                ->references(['tenant_id', 'id'])->on('booking_quotes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reservation_id'], 'voucher_redemptions_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations')->restrictOnDelete();
        });

        Schema::create('voucher_redemption_events', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('voucher_redemption_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 24);
            $table->string('policy_reason')->nullable();
            $table->json('facts');
            $table->timestampTz('occurred_at');
            $table->timestamps();
            $table->foreign(['tenant_id', 'voucher_redemption_id'], 'voucher_events_tenant_redemption_fk')
                ->references(['tenant_id', 'id'])->on('voucher_redemptions')->restrictOnDelete();
            $table->index(['tenant_id', 'voucher_redemption_id', 'occurred_at'], 'voucher_events_history_idx');
        });

        Schema::create('fiscal_source_snapshots', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('reservation_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('reservation_revision');
            $table->string('source_type', 32)->default('operational_folio');
            $table->char('currency', 3);
            $table->bigInteger('net_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('gross_minor');
            $table->json('source_snapshot');
            $table->char('checksum', 64);
            $table->timestampTz('captured_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'reservation_id', 'reservation_revision', 'source_type'], 'fiscal_source_snapshot_unique');
            $table->foreign(['tenant_id', 'property_id'], 'fiscal_snapshots_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reservation_id'], 'fiscal_snapshots_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX commercial_promotions_version_unique ON commercial_promotions (tenant_id, COALESCE(property_id, '00000000-0000-0000-0000-000000000000'::uuid), name, version)");
            DB::statement("ALTER TABLE commercial_promotions ADD CONSTRAINT commercial_promotions_discount_check CHECK ((discount_type = 'percentage' AND percentage_basis_points BETWEEN 1 AND 10000 AND fixed_amount_minor IS NULL) OR (discount_type = 'fixed' AND fixed_amount_minor > 0 AND percentage_basis_points IS NULL))");
            DB::statement("ALTER TABLE vouchers ADD CONSTRAINT vouchers_state_check CHECK (state IN ('active', 'suspended', 'retired'))");
            DB::statement("ALTER TABLE voucher_redemptions ADD CONSTRAINT voucher_redemptions_state_check CHECK (state IN ('reserved', 'confirmed', 'released', 'reinstated'))");
        } else {
            DB::statement("CREATE UNIQUE INDEX commercial_promotions_version_unique ON commercial_promotions (tenant_id, ifnull(property_id, '00000000-0000-0000-0000-000000000000'), name, version)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_source_snapshots');
        Schema::dropIfExists('voucher_redemption_events');
        Schema::dropIfExists('voucher_redemptions');
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('commercial_promotions');
        Schema::dropIfExists('rate_plan_services');

        Schema::table('booking_quote_lines', function (Blueprint $table): void {
            $table->dropColumn(['basis', 'calculation_order', 'pre_total_minor', 'post_total_minor', 'rounding_mode', 'explanation']);
        });
        Schema::table('booking_quotes', function (Blueprint $table): void {
            $table->dropColumn(['infants', 'discount_minor', 'calculation_snapshot']);
        });
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropColumn('infants');
        });
        Schema::table('tax_rules', function (Blueprint $table): void {
            $table->dropUnique('tax_rules_version_unique');
            $table->dropForeign(['supersedes_id']);
            $table->dropForeign(['approved_by']);
            $table->dropColumn([
                'version', 'state', 'supersedes_id', 'taxable_discount_allocation', 'rounding_mode', 'rounding_scope',
                'jurisdiction_inputs', 'published_at', 'retired_at', 'approved_by',
            ]);
            $table->unique(['tenant_id', 'property_id', 'name']);
        });
        Schema::table('rate_rules', function (Blueprint $table): void {
            $table->dropForeign(['program_id']);
            $table->dropColumn([
                'name', 'program_id', 'version', 'component_type', 'minimum_advance_days', 'maximum_advance_days',
                'allowed_arrival_days', 'blackout', 'minimum_occupancy', 'maximum_occupancy', 'buyout_only',
                'adult_amount_minor', 'child_amount_minor', 'infant_amount_minor', 'single_supplement_minor',
                'group_tiers', 'length_of_stay_adjustment_basis_points',
            ]);
        });
        Schema::table('rate_plans', function (Blueprint $table): void {
            $table->dropUnique('rate_plans_version_unique');
            $table->dropForeign(['supersedes_id']);
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['version', 'state', 'supersedes_id', 'published_at', 'retired_at', 'approved_by']);
            $table->unique(['tenant_id', 'property_id', 'name', 'currency']);
        });
    }

    private function tenantUuid(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
        $table->unique(['tenant_id', 'id']);
    }
};
