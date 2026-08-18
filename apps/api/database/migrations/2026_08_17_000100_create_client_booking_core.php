<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_policies', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('requirement_type', 20)->default('percentage');
            $table->unsignedInteger('percentage_basis_points')->nullable();
            $table->unsignedBigInteger('fixed_amount_minor')->nullable();
            $table->integer('deposit_due_offset_days')->default(0);
            $table->integer('balance_due_offset_days')->default(30);
            $table->boolean('confirmation_requires_payment')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'property_id', 'name']);
            $table->foreign(['tenant_id', 'property_id'], 'deposit_policies_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
        });

        Schema::create('cancellation_policies', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('summary')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'property_id', 'name']);
            $table->foreign(['tenant_id', 'property_id'], 'cancellation_policies_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
        });

        Schema::create('cancellation_policy_tiers', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('cancellation_policy_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('days_before_arrival');
            $table->unsignedInteger('retained_basis_points')->default(0);
            $table->unsignedBigInteger('minimum_fee_minor')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'cancellation_policy_id', 'days_before_arrival'], 'cancellation_policy_tier_unique');
            $table->foreign(['tenant_id', 'cancellation_policy_id'], 'cancellation_tiers_tenant_policy_fk')
                ->references(['tenant_id', 'id'])->on('cancellation_policies')->cascadeOnDelete();
        });

        Schema::create('rate_plans', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('deposit_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('cancellation_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->char('currency', 3);
            $table->string('source_scope', 50)->nullable();
            $table->date('active_from')->nullable();
            $table->date('active_until')->nullable();
            $table->unsignedSmallInteger('minimum_occupancy')->default(1);
            $table->unsignedSmallInteger('maximum_occupancy')->nullable();
            $table->json('inclusions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'property_id', 'name', 'currency']);
            $table->foreign(['tenant_id', 'property_id'], 'rate_plans_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'deposit_policy_id'], 'rate_plans_tenant_deposit_policy_fk')
                ->references(['tenant_id', 'id'])->on('deposit_policies');
            $table->foreign(['tenant_id', 'cancellation_policy_id'], 'rate_plans_tenant_cancellation_policy_fk')
                ->references(['tenant_id', 'id'])->on('cancellation_policies');
        });

        Schema::create('rate_rules', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('rate_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('resource_category_id')->nullable()->constrained()->nullOnDelete();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->json('weekdays')->nullable();
            $table->string('price_type', 24)->default('per_night');
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedSmallInteger('minimum_stay')->default(1);
            $table->unsignedSmallInteger('maximum_stay')->nullable();
            $table->boolean('closed_to_arrival')->default(false);
            $table->boolean('closed_to_departure')->default(false);
            $table->boolean('stop_sell')->default(false);
            $table->integer('priority')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'rate_plan_id', 'resource_category_id', 'starts_on', 'ends_on'], 'rate_rules_lookup_idx');
            $table->foreign(['tenant_id', 'rate_plan_id'], 'rate_rules_tenant_plan_fk')
                ->references(['tenant_id', 'id'])->on('rate_plans')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'resource_category_id'], 'rate_rules_tenant_category_fk')
                ->references(['tenant_id', 'id'])->on('resource_categories');
        });

        Schema::create('tax_rules', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('calculation_type', 20)->default('percentage');
            $table->unsignedInteger('percentage_basis_points')->nullable();
            $table->unsignedBigInteger('fixed_amount_minor')->nullable();
            $table->boolean('is_inclusive')->default(false);
            $table->date('active_from')->nullable();
            $table->date('active_until')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'property_id', 'name']);
            $table->foreign(['tenant_id', 'property_id'], 'tax_rules_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
        });

        Schema::create('booking_quotes', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('rate_plan_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('resource_category_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('resource_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('program_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->unsignedSmallInteger('adults');
            $table->unsignedSmallInteger('children')->default(0);
            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('total_minor');
            $table->json('inputs');
            $table->json('deposit_policy_snapshot')->nullable();
            $table->json('cancellation_policy_snapshot')->nullable();
            $table->char('checksum', 64);
            $table->string('status', 24)->default('pending');
            $table->timestampTz('expires_at');
            $table->timestampTz('committed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'property_id', 'status', 'expires_at'], 'booking_quotes_pending_idx');
            $table->foreign(['tenant_id', 'property_id'], 'booking_quotes_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'rate_plan_id'], 'booking_quotes_tenant_plan_fk')
                ->references(['tenant_id', 'id'])->on('rate_plans');
            $table->foreign(['tenant_id', 'resource_category_id'], 'booking_quotes_tenant_category_fk')
                ->references(['tenant_id', 'id'])->on('resource_categories');
            $table->foreign(['tenant_id', 'resource_id'], 'booking_quotes_tenant_resource_fk')
                ->references(['tenant_id', 'id'])->on('resources');
            $table->foreign(['tenant_id', 'program_id'], 'booking_quotes_tenant_program_fk')
                ->references(['tenant_id', 'id'])->on('programs');
            $table->foreign(['tenant_id', 'reservation_id'], 'booking_quotes_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations');
        });

        Schema::create('booking_quote_lines', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('booking_quote_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24);
            $table->string('description');
            $table->date('service_on')->nullable();
            $table->unsignedInteger('quantity_thousandths')->default(1000);
            $table->bigInteger('unit_amount_minor');
            $table->bigInteger('net_amount_minor');
            $table->bigInteger('tax_amount_minor')->default(0);
            $table->bigInteger('gross_amount_minor');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'booking_quote_id'], 'booking_quote_lines_tenant_quote_fk')
                ->references(['tenant_id', 'id'])->on('booking_quotes')->cascadeOnDelete();
        });

        Schema::table('reservations', function (Blueprint $table): void {
            $table->foreignUuid('booking_quote_id')->nullable()->after('primary_guest_id')->constrained()->nullOnDelete();
            $table->json('price_snapshot')->nullable()->after('total_minor');
            $table->json('deposit_policy_snapshot')->nullable()->after('price_snapshot');
            $table->json('cancellation_policy_snapshot')->nullable()->after('deposit_policy_snapshot');
            $table->foreign(['tenant_id', 'booking_quote_id'], 'reservations_tenant_booking_quote_fk')
                ->references(['tenant_id', 'id'])->on('booking_quotes');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('reservations', function (Blueprint $table): void {
                $table->dropForeign('reservations_tenant_booking_quote_fk');
                $table->dropForeign(['booking_quote_id']);
                $table->dropColumn(['booking_quote_id', 'price_snapshot', 'deposit_policy_snapshot', 'cancellation_policy_snapshot']);
            });
        }
        Schema::dropIfExists('booking_quote_lines');
        Schema::dropIfExists('booking_quotes');
        Schema::dropIfExists('tax_rules');
        Schema::dropIfExists('rate_rules');
        Schema::dropIfExists('rate_plans');
        Schema::dropIfExists('cancellation_policy_tiers');
        Schema::dropIfExists('cancellation_policies');
        Schema::dropIfExists('deposit_policies');
    }

    private function tenantUuid(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
        $table->unique(['tenant_id', 'id']);
    }
};
