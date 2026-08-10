<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('reservations', 'hold_expires_at')) {
            return;
        }

        Schema::table('reservations', function (Blueprint $table): void {
            $table->timestampTz('hold_expires_at')->nullable()->after('confirmed_at');
            $table->index(
                ['tenant_id', 'status', 'hold_expires_at'],
                'reservations_hold_expiry_idx',
            );
        });
    }

    public function down(): void
    {
        // Intentionally retained. The original operations migration owns this
        // column on clean installs; this migration repairs older installations
        // where that already-recorded migration predated the hold-expiry field.
    }
};
