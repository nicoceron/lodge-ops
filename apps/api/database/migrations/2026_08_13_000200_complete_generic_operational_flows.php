<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('allocations', function (Blueprint $table): void {
            $table->foreignUuid('requested_category_id')->nullable()->after('reservation_id');
            $table->foreign(['tenant_id', 'requested_category_id'], 'allocations_tenant_requested_category_fk')
                ->references(['tenant_id', 'id'])->on('resource_categories')->restrictOnDelete();
            $table->index(['tenant_id', 'requested_category_id', 'starts_at', 'ends_at'], 'allocations_requested_category_interval_idx');
        });

        DB::table('allocations')
            ->whereNotNull('resource_id')
            ->orderBy('id')
            ->each(function (object $allocation): void {
                $categoryId = DB::table('resources')->where('id', $allocation->resource_id)->value('category_id');
                if ($categoryId !== null) {
                    DB::table('allocations')->where('id', $allocation->id)->update(['requested_category_id' => $categoryId]);
                }
            });

        Schema::table('resources', function (Blueprint $table): void {
            $table->string('housekeeping_status', 24)->nullable()->after('is_buyout');
            $table->timestampTz('housekeeping_updated_at')->nullable()->after('housekeeping_status');
            $table->foreignId('housekeeping_updated_by')->nullable()->after('housekeeping_updated_at')
                ->constrained('users')->nullOnDelete();
            $table->index(['tenant_id', 'property_id', 'housekeeping_status'], 'resources_housekeeping_status_idx');
        });

        Schema::table('reservations', function (Blueprint $table): void {
            $table->string('folio_status', 16)->default('open')->after('total_minor');
            $table->timestampTz('folio_closed_at')->nullable()->after('folio_status');
            $table->foreignId('folio_closed_by')->nullable()->after('folio_closed_at')
                ->constrained('users')->nullOnDelete();
            $table->index(['tenant_id', 'folio_status'], 'reservations_folio_status_idx');
        });

        Schema::table('folio_lines', function (Blueprint $table): void {
            $table->bigInteger('net_amount_minor')->default(0)->after('unit_amount_minor');
            $table->bigInteger('tax_amount_minor')->default(0)->after('net_amount_minor');
            $table->bigInteger('gross_amount_minor')->default(0)->after('tax_amount_minor');
        });
        DB::table('folio_lines')->update([
            'net_amount_minor' => DB::raw('amount_minor'),
            'tax_amount_minor' => 0,
            'gross_amount_minor' => DB::raw('amount_minor'),
        ]);

        Schema::create('reservation_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 32)->default('internal');
            $table->text('body');
            $table->timestampTz('occurred_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'reservation_id', 'occurred_at'], 'reservation_notes_timeline_idx');
            $table->foreign(['tenant_id', 'reservation_id'], 'reservation_notes_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations')->cascadeOnDelete();
        });

        Schema::create('calendar_feeds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('resource_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('token');
            $table->char('token_hash', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_accessed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'property_id', 'is_active'], 'calendar_feeds_property_active_idx');
            $table->foreign(['tenant_id', 'property_id'], 'calendar_feeds_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'resource_id'], 'calendar_feeds_tenant_resource_fk')
                ->references(['tenant_id', 'id'])->on('resources')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_feeds');
        Schema::dropIfExists('reservation_notes');

        Schema::table('folio_lines', function (Blueprint $table): void {
            $table->dropColumn(['net_amount_minor', 'tax_amount_minor', 'gross_amount_minor']);
        });

        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropForeign(['folio_closed_by']);
            $table->dropIndex('reservations_folio_status_idx');
            $table->dropColumn(['folio_status', 'folio_closed_at', 'folio_closed_by']);
        });

        Schema::table('resources', function (Blueprint $table): void {
            $table->dropForeign(['housekeeping_updated_by']);
            $table->dropIndex('resources_housekeeping_status_idx');
            $table->dropColumn(['housekeeping_status', 'housekeeping_updated_at', 'housekeeping_updated_by']);
        });

        Schema::table('allocations', function (Blueprint $table): void {
            DB::getDriverName() === 'sqlite'
                ? $table->dropForeign(['tenant_id', 'requested_category_id'])
                : $table->dropForeign('allocations_tenant_requested_category_fk');
            $table->dropIndex('allocations_requested_category_interval_idx');
            $table->dropColumn('requested_category_id');
        });
    }
};
