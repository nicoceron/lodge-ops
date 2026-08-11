<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table): void {
            $table->foreignUuid('property_id')->nullable()->after('tenant_id');
            $table->index(
                ['tenant_id', 'property_id', 'base_currency', 'quote_currency', 'effective_at'],
                'exchange_rates_lookup_idx',
            );
            $table->foreign(['tenant_id', 'property_id'], 'exchange_rates_tenant_property_fk')
                ->references(['tenant_id', 'id'])
                ->on('properties')
                ->nullOnDelete();
        });

        Schema::table('exchange_rates', function (Blueprint $table): void {
            $table->dropUnique('exchange_rates_snapshot_unique');
            $table->unique(
                ['tenant_id', 'property_id', 'base_currency', 'quote_currency', 'effective_at'],
                'exchange_rates_snapshot_property_unique',
            );
        });

        DB::statement(
            'CREATE UNIQUE INDEX exchange_rates_snapshot_tenant_unique '
            .'ON exchange_rates (tenant_id, base_currency, quote_currency, effective_at) '
            .'WHERE property_id IS NULL',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('exchange_rates', function (Blueprint $table): void {
                $table->dropForeign(['tenant_id', 'property_id']);
            });
        } else {
            Schema::table('exchange_rates', function (Blueprint $table): void {
                $table->dropForeign('exchange_rates_tenant_property_fk');
            });
        }

        DB::statement('DROP INDEX IF EXISTS exchange_rates_snapshot_tenant_unique');

        Schema::table('exchange_rates', function (Blueprint $table): void {
            $table->dropUnique('exchange_rates_snapshot_property_unique');
            $table->dropIndex('exchange_rates_lookup_idx');
            $table->dropColumn('property_id');
            $table->unique(
                ['tenant_id', 'base_currency', 'quote_currency', 'effective_at'],
                'exchange_rates_snapshot_unique',
            );
        });
    }
};
