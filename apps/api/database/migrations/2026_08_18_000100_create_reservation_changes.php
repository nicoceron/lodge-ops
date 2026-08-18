<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            $table->uuid('parent_change_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40);
            $table->string('status', 24)->default('completed');
            $table->char('currency', 3)->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->string('reference')->nullable();
            $table->string('deduplication_key')->nullable();
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'deduplication_key'], 'reservation_changes_deduplication_unique');
            $table->index(['tenant_id', 'reservation_id', 'occurred_at'], 'reservation_changes_timeline_idx');
            $table->index(['tenant_id', 'parent_change_id', 'type'], 'reservation_changes_parent_type_idx');
            $table->foreign(['tenant_id', 'reservation_id'], 'reservation_changes_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'parent_change_id'], 'reservation_changes_tenant_parent_fk')
                ->references(['tenant_id', 'id'])->on('reservation_changes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_changes');
    }
};
