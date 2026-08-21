<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_booking_command_responses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->uuid('idempotency_key');
            $table->string('command', 240);
            $table->char('request_checksum', 64);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->text('response_body_encrypted')->nullable();
            $table->json('response_headers')->nullable();
            $table->timestampTz('lease_expires_at');
            $table->timestampTz('expires_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key'], 'direct_booking_commands_retry_unique');
            $table->index(['tenant_id', 'expires_at'], 'direct_booking_commands_expiry_idx');
            $table->foreign(['tenant_id', 'property_id'], 'direct_booking_commands_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_booking_command_responses');
    }
};
