<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
        });

        Schema::table('reservations', function (Blueprint $table): void {
            $table->timestampTz('hold_expires_at')->nullable()->after('confirmed_at');
            $table->index(['tenant_id', 'status', 'hold_expires_at'], 'reservations_hold_expiry_idx');
        });

        Schema::table('guests', function (Blueprint $table): void {
            $table->foreignUuid('merged_into_id')->nullable()->after('marketing_consent');
            $table->timestampTz('merged_at')->nullable()->after('merged_into_id');
            $table->foreign(['tenant_id', 'merged_into_id'], 'guests_merged_into_tenant_fk')
                ->references(['tenant_id', 'id'])->on('guests');
        });

        Schema::create('organizations', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->string('name');
            $table->string('type', 32)->default('agency');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->unsignedInteger('commission_basis_points')->default(0);
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name', 'type']);
        });

        Schema::create('opportunities', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('proposal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('stage', 32)->default('inquiry');
            $table->string('source', 50)->nullable();
            $table->char('currency', 3);
            $table->bigInteger('value_minor')->default(0);
            $table->date('expected_close_on')->nullable();
            $table->text('lost_reason')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'property_id'], 'opportunities_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties');
            $table->foreign(['tenant_id', 'guest_id'], 'opportunities_tenant_guest_fk')
                ->references(['tenant_id', 'id'])->on('guests');
            $table->foreign(['tenant_id', 'organization_id'], 'opportunities_tenant_organization_fk')
                ->references(['tenant_id', 'id'])->on('organizations');
            $table->foreign(['tenant_id', 'proposal_id'], 'opportunities_tenant_proposal_fk')
                ->references(['tenant_id', 'id'])->on('proposals');
            $table->index(['tenant_id', 'stage', 'expected_close_on']);
        });

        Schema::create('crm_activities', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('opportunity_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 32);
            $table->string('subject');
            $table->text('body')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'opportunity_id'], 'crm_activities_tenant_opportunity_fk')
                ->references(['tenant_id', 'id'])->on('opportunities')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'guest_id'], 'crm_activities_tenant_guest_fk')
                ->references(['tenant_id', 'id'])->on('guests');
            $table->index(['tenant_id', 'due_at', 'completed_at']);
        });

        Schema::create('guest_merge_aliases', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('guest_id')->constrained()->cascadeOnDelete();
            $table->uuid('source_guest_id');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->timestampTz('merged_at');
            $table->timestamps();
            $table->foreign(['tenant_id', 'guest_id'], 'guest_aliases_tenant_guest_fk')
                ->references(['tenant_id', 'id'])->on('guests')->cascadeOnDelete();
            $table->unique(['tenant_id', 'source_guest_id']);
        });

        Schema::create('exchange_rates', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->decimal('rate', 20, 10);
            $table->string('source', 80);
            $table->timestampTz('effective_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'base_currency', 'quote_currency', 'effective_at'], 'exchange_rates_snapshot_unique');
        });

        Schema::create('message_templates', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->string('key', 80);
            $table->string('name');
            $table->string('channel', 32);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'key', 'channel']);
        });

        Schema::create('message_template_versions', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('message_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('language', 12)->default('en');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->timestampTz('published_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'message_template_id', 'version'], 'message_template_version_unique');
            $table->foreign(['tenant_id', 'message_template_id'], 'message_versions_tenant_template_fk')
                ->references(['tenant_id', 'id'])->on('message_templates')->cascadeOnDelete();
        });

        Schema::create('communication_suppressions', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->string('channel', 32);
            $table->string('recipient_hash', 64);
            $table->string('reason', 80);
            $table->timestampTz('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'channel', 'recipient_hash']);
        });

        Schema::create('delivery_attempts', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('communication_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 80);
            $table->string('status', 32);
            $table->string('idempotency_key', 160);
            $table->unsignedInteger('attempt')->default(1);
            $table->string('provider_reference')->nullable();
            $table->text('response')->nullable();
            $table->timestampTz('attempted_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key', 'attempt']);
            $table->foreign(['tenant_id', 'communication_id'], 'delivery_attempts_tenant_communication_fk')
                ->references(['tenant_id', 'id'])->on('communications')->cascadeOnDelete();
        });

        Schema::create('document_templates', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->string('name');
            $table->string('kind', 50);
            $table->unsignedInteger('version');
            $table->json('definition');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'kind', 'version']);
        });

        Schema::create('generated_documents', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('document_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 50);
            $table->string('status', 32)->default('generated');
            $table->string('storage_path');
            $table->char('checksum', 64);
            $table->timestampTz('signed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'document_template_id'], 'documents_tenant_template_fk')
                ->references(['tenant_id', 'id'])->on('document_templates');
            $table->foreign(['tenant_id', 'reservation_id'], 'documents_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations');
            $table->foreign(['tenant_id', 'guest_id'], 'documents_tenant_guest_fk')
                ->references(['tenant_id', 'id'])->on('guests');
            $table->index(['tenant_id', 'reservation_id', 'kind']);
        });

        Schema::create('integration_connections', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->string('name');
            $table->string('type', 50);
            $table->string('status', 32)->default('disconnected');
            $table->string('secret_reference')->nullable();
            $table->json('configuration')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'type', 'name']);
        });

        Schema::create('catalog_items', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->string('sku');
            $table->string('name');
            $table->string('type', 32)->default('retail');
            $table->char('currency', 3);
            $table->bigInteger('price_minor');
            $table->bigInteger('cost_minor')->default(0);
            $table->boolean('track_stock')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'sku']);
        });

        Schema::create('stock_locations', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->foreign(['tenant_id', 'property_id'], 'stock_locations_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
        });

        Schema::create('retail_sales', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('stock_location_id')->constrained()->restrictOnDelete();
            $table->string('reference');
            $table->string('status', 32)->default('posted');
            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->timestampTz('posted_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'reference']);
            $table->foreign(['tenant_id', 'reservation_id'], 'retail_sales_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations');
            $table->foreign(['tenant_id', 'stock_location_id'], 'retail_sales_tenant_location_fk')
                ->references(['tenant_id', 'id'])->on('stock_locations');
        });

        Schema::create('retail_sale_lines', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('retail_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('catalog_item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('folio_line_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 10, 3);
            $table->bigInteger('unit_amount_minor');
            $table->bigInteger('amount_minor');
            $table->timestamps();
            $table->foreign(['tenant_id', 'retail_sale_id'], 'sale_lines_tenant_sale_fk')
                ->references(['tenant_id', 'id'])->on('retail_sales')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'catalog_item_id'], 'sale_lines_tenant_item_fk')
                ->references(['tenant_id', 'id'])->on('catalog_items');
            $table->foreign(['tenant_id', 'folio_line_id'], 'sale_lines_tenant_folio_fk')
                ->references(['tenant_id', 'id'])->on('folio_lines');
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('catalog_item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('stock_location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('retail_sale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->decimal('quantity', 12, 3);
            $table->bigInteger('unit_cost_minor')->default(0);
            $table->string('reference');
            $table->timestampTz('occurred_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'reference']);
            $table->foreign(['tenant_id', 'catalog_item_id'], 'stock_movements_tenant_item_fk')
                ->references(['tenant_id', 'id'])->on('catalog_items');
            $table->foreign(['tenant_id', 'stock_location_id'], 'stock_movements_tenant_location_fk')
                ->references(['tenant_id', 'id'])->on('stock_locations');
            $table->foreign(['tenant_id', 'retail_sale_id'], 'stock_movements_tenant_sale_fk')
                ->references(['tenant_id', 'id'])->on('retail_sales');
            $table->index(['tenant_id', 'catalog_item_id', 'stock_location_id', 'occurred_at'], 'stock_balance_idx');
        });

        Schema::create('cost_records', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('program_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('staff_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 32);
            $table->string('category', 50);
            $table->string('description');
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->timestampTz('occurred_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'reservation_id'], 'cost_records_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations');
            $table->foreign(['tenant_id', 'program_id'], 'cost_records_tenant_program_fk')
                ->references(['tenant_id', 'id'])->on('programs');
            $table->index(['tenant_id', 'occurred_at', 'category']);
        });

        Schema::create('commission_accruals', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('reservation_id')->constrained()->restrictOnDelete();
            $table->string('payee_type', 32);
            $table->string('payee_name');
            $table->unsignedInteger('rate_basis_points');
            $table->bigInteger('base_amount_minor');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 32)->default('accrued');
            $table->timestampTz('paid_at')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'reservation_id'], 'commissions_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations');
            $table->index(['tenant_id', 'status', 'paid_at']);
        });

        Schema::create('report_exports', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('kind', 50);
            $table->json('filters')->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('storage_path')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'requested_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('commission_accruals');
        Schema::dropIfExists('cost_records');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('retail_sale_lines');
        Schema::dropIfExists('retail_sales');
        Schema::dropIfExists('stock_locations');
        Schema::dropIfExists('catalog_items');
        Schema::dropIfExists('integration_connections');
        Schema::dropIfExists('generated_documents');
        Schema::dropIfExists('document_templates');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('delivery_attempts');
        Schema::dropIfExists('communication_suppressions');
        Schema::dropIfExists('message_template_versions');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('guest_merge_aliases');
        Schema::dropIfExists('crm_activities');
        Schema::dropIfExists('opportunities');
        Schema::dropIfExists('organizations');
        Schema::table('guests', function (Blueprint $table): void {
            DB::getDriverName() === 'sqlite'
                ? $table->dropForeign(['tenant_id', 'merged_into_id'])
                : $table->dropForeign('guests_merged_into_tenant_fk');
            $table->dropColumn(['merged_into_id', 'merged_at']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes']);
        });
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropIndex('reservations_hold_expiry_idx');
            $table->dropColumn('hold_expires_at');
        });
    }

    private function tenantUuid(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
        $table->unique(['tenant_id', 'id']);
    }
};
