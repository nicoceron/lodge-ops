<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->timestampTz('actual_start_at')->nullable()->after('confirmed_at');
            $table->timestampTz('actual_end_at')->nullable()->after('actual_start_at');
            $table->timestampTz('cancelled_at')->nullable()->after('actual_end_at');
            $table->string('closure_reason', 500)->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropColumn(['actual_start_at', 'actual_end_at', 'cancelled_at', 'closure_reason']);
        });
    }
};
