<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_portal_access_tokens', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->char('session_hash', 64)->nullable()->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('exchanged_at')->nullable();
            $table->timestampTz('session_expires_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'reservation_id', 'revoked_at'], 'guest_portal_access_reservation_idx');
            $table->foreign(['tenant_id', 'reservation_id'], 'guest_portal_access_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'guest_id'], 'guest_portal_access_tenant_guest_fk')
                ->references(['tenant_id', 'id'])->on('guests')->cascadeOnDelete();
        });

        Schema::create('guest_portal_profiles', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_id')->constrained()->cascadeOnDelete();
            $table->json('profile');
            $table->json('travel');
            $table->json('preferences');
            $table->timestampTz('consented_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'reservation_id', 'guest_id'], 'guest_portal_profiles_unique');
            $table->foreign(['tenant_id', 'reservation_id'], 'guest_portal_profiles_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'guest_id'], 'guest_portal_profiles_tenant_guest_fk')
                ->references(['tenant_id', 'id'])->on('guests')->cascadeOnDelete();
        });

        Schema::create('guest_portal_documents', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 80);
            $table->string('title');
            $table->string('version', 40);
            $table->longText('body');
            $table->char('body_hash', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'property_id', 'slug', 'version'], 'guest_portal_documents_version_unique');
            $table->index(['tenant_id', 'property_id', 'slug', 'is_active'], 'guest_portal_documents_active_idx');
            $table->foreign(['tenant_id', 'property_id'], 'guest_portal_documents_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
        });

        Schema::create('guest_portal_acknowledgements', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained('guest_portal_documents')->restrictOnDelete();
            $table->string('signature');
            $table->char('document_hash', 64);
            $table->timestampTz('acknowledged_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'reservation_id', 'guest_id', 'document_id'], 'guest_portal_ack_unique');
            $table->foreign(['tenant_id', 'reservation_id'], 'guest_portal_ack_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'guest_id'], 'guest_portal_ack_tenant_guest_fk')
                ->references(['tenant_id', 'id'])->on('guests')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'document_id'], 'guest_portal_ack_tenant_document_fk')
                ->references(['tenant_id', 'id'])->on('guest_portal_documents')->restrictOnDelete();
        });

        Schema::create('guest_payment_evidence', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_id')->constrained()->cascadeOnDelete();
            $table->string('file_name');
            $table->string('content_type', 80);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('storage_path');
            $table->string('status', 32)->default('review_pending');
            $table->timestampTz('submitted_at');
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'reservation_id', 'status'], 'guest_payment_evidence_review_idx');
            $table->foreign(['tenant_id', 'reservation_id'], 'guest_payment_evidence_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'guest_id'], 'guest_payment_evidence_tenant_guest_fk')
                ->references(['tenant_id', 'id'])->on('guests')->cascadeOnDelete();
        });

        Schema::table('surveys', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'reservation_id', 'guest_id', 'kind'], 'guest_portal_surveys_unique');
        });
    }

    public function down(): void
    {
        Schema::table('surveys', function (Blueprint $table): void {
            $table->dropUnique('guest_portal_surveys_unique');
        });
        Schema::dropIfExists('guest_payment_evidence');
        Schema::dropIfExists('guest_portal_acknowledgements');
        Schema::dropIfExists('guest_portal_documents');
        Schema::dropIfExists('guest_portal_profiles');
        Schema::dropIfExists('guest_portal_access_tokens');
    }

    private function tenantUuid(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
        $table->unique(['tenant_id', 'id']);
    }
};
