<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_payment_evidence', function (Blueprint $table): void {
            $table->unique(
                ['tenant_id', 'reservation_id', 'guest_id', 'sha256'],
                'guest_evidence_submission_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('guest_payment_evidence', function (Blueprint $table): void {
            $table->dropUnique('guest_evidence_submission_unique');
        });
    }
};
