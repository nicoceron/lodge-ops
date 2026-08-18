<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('origin', 24)->default('manual')->after('method');
            $table->index(['tenant_id', 'origin', 'status'], 'payments_origin_status_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_origin_check CHECK (origin IN ('manual', 'provider'))");
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_provider_origin_check CHECK (origin = 'manual' OR (provider IS NOT NULL AND provider_reference IS NOT NULL))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_provider_origin_check');
            DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_origin_check');
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('payments_origin_status_idx');
            $table->dropColumn('origin');
        });
    }
};
