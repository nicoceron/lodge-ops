<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_payment_evidence', function (Blueprint $table): void {
            $table->bigInteger('amount_minor')->nullable()->after('status');
            $table->char('currency', 3)->nullable()->after('amount_minor');
            $table->string('transfer_reference')->nullable()->after('currency');
            $table->string('scan_status', 24)->default('accepted')->after('transfer_reference');
            $table->text('reviewer_note')->nullable()->after('reviewed_by');
            $table->text('requested_information_note')->nullable()->after('reviewer_note');
            $table->foreignUuid('payment_id')->nullable()->after('requested_information_note')->constrained()->nullOnDelete();
            $table->timestampTz('decided_at')->nullable()->after('payment_id');
            $table->unique(['tenant_id', 'payment_id'], 'guest_payment_evidence_payment_unique');
            $table->foreign(['tenant_id', 'payment_id'], 'guest_payment_evidence_tenant_payment_fk')
                ->references(['tenant_id', 'id'])->on('payments');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        Schema::table('guest_payment_evidence', function (Blueprint $table): void {
            $table->dropForeign('guest_payment_evidence_tenant_payment_fk');
            $table->dropForeign(['payment_id']);
            $table->dropUnique('guest_payment_evidence_payment_unique');
            $table->dropColumn([
                'amount_minor',
                'currency',
                'transfer_reference',
                'scan_status',
                'reviewer_note',
                'requested_information_note',
                'payment_id',
                'decided_at',
            ]);
        });
    }
};
