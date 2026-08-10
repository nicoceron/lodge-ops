<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox', function (Blueprint $table): void {
            $table->uuid('claim_token')->nullable()->after('published_at');
            $table->timestampTz('claimed_at')->nullable()->after('claim_token');
            $table->index(['published_at', 'claimed_at', 'available_at'], 'outbox_delivery_idx');
        });

        Schema::table('operational_tasks', function (Blueprint $table): void {
            $table->string('automation_key', 190)->nullable()->after('metadata');
            $table->unique(['tenant_id', 'automation_key'], 'tasks_automation_key_unique');
        });

        Schema::table('communications', function (Blueprint $table): void {
            $table->string('automation_key', 190)->nullable()->after('metadata');
            $table->unique(['tenant_id', 'automation_key'], 'communications_automation_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('communications', function (Blueprint $table): void {
            $table->dropUnique('communications_automation_key_unique');
            $table->dropColumn('automation_key');
        });

        Schema::table('operational_tasks', function (Blueprint $table): void {
            $table->dropUnique('tasks_automation_key_unique');
            $table->dropColumn('automation_key');
        });

        Schema::table('outbox', function (Blueprint $table): void {
            $table->dropIndex('outbox_delivery_idx');
            $table->dropColumn(['claim_token', 'claimed_at']);
        });
    }
};
