<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_automation_milestones', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->timestampTz('occurred_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'reservation_id', 'key'], 'reservation_milestone_unique');
            $table->foreign(['tenant_id', 'reservation_id'], 'reservation_milestone_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_automation_milestones');
    }
};
