<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone')->default('UTC');
            $table->char('currency', 3)->default('USD');
            $table->string('locale', 12)->default('en');
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('properties', function (Blueprint $table) {
            $this->tenantUuid($table);
            $table->string('name');
            $table->string('code');
            $table->string('timezone')->default('UTC');
            $table->text('address')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('memberships', function (Blueprint $table) {
            $this->tenantUuid($table);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('property_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role', 32)->default('staff');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
            $table->foreign(['tenant_id', 'property_id'], 'memberships_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties');
        });

        Schema::create('guests', function (Blueprint $table) {
            $this->tenantUuid($table);
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('document_type', 40)->nullable();
            $table->string('document_number', 100)->nullable();
            $table->string('language', 12)->nullable();
            $table->json('preferences')->nullable();
            $table->boolean('marketing_consent')->default(false);
            $table->timestamps();
            $table->index(['tenant_id', 'last_name', 'first_name']);
            $table->unique(['tenant_id', 'email']);
        });

        Schema::create('programs', function (Blueprint $table) {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('default_duration_minutes')->default(60);
            $table->unsignedInteger('capacity')->default(1);
            $table->bigInteger('price_minor')->default(0);
            $table->char('currency', 3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'property_id', 'is_active']);
            $table->foreign(['tenant_id', 'property_id'], 'programs_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
        });

        Schema::create('resources', function (Blueprint $table) {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('type', 32);
            $table->unsignedInteger('capacity')->default(1);
            $table->json('attributes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'property_id', 'type']);
            $table->foreign(['tenant_id', 'property_id'], 'resources_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
        });

        Schema::create('resource_blocks', function (Blueprint $table) {
            $this->tenantUuid($table);
            $table->foreignUuid('resource_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('reason');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'resource_id', 'starts_at', 'ends_at'], 'resource_blocks_interval_idx');
            $table->foreign(['tenant_id', 'resource_id'], 'resource_blocks_tenant_resource_fk')
                ->references(['tenant_id', 'id'])->on('resources')->cascadeOnDelete();
        });

        Schema::create('reservations', function (Blueprint $table) {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('primary_guest_id')->nullable()->constrained('guests')->nullOnDelete();
            $table->string('confirmation_number');
            $table->string('status', 32)->default('draft');
            $table->string('source', 50)->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->unsignedSmallInteger('adults')->default(1);
            $table->unsignedSmallInteger('children')->default(0);
            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->text('notes')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'confirmation_number']);
            $table->index(['tenant_id', 'property_id', 'starts_at', 'ends_at'], 'reservations_interval_idx');
            $table->index(['tenant_id', 'status']);
            $table->foreign(['tenant_id', 'property_id'], 'reservations_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
            $table->foreign(['tenant_id', 'primary_guest_id'], 'reservations_tenant_guest_fk')
                ->references(['tenant_id', 'id'])->on('guests');
        });

        Schema::create('reservation_guests', function (Blueprint $table) {
            $this->tenantUuid($table);
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32)->default('guest');
            $table->timestamps();
            $table->unique(['tenant_id', 'reservation_id', 'guest_id'], 'reservation_guests_unique');
            $table->foreign(['tenant_id', 'reservation_id'], 'reservation_guests_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'guest_id'], 'reservation_guests_tenant_guest_fk')
                ->references(['tenant_id', 'id'])->on('guests')->cascadeOnDelete();
        });

        Schema::create('service_occurrences', function (Blueprint $table) {
            $this->tenantUuid($table);
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->unsignedInteger('capacity')->default(1);
            $table->boolean('is_cancelled')->default(false);
            $table->string('meeting_point')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'property_id', 'starts_at', 'ends_at'], 'occurrences_interval_idx');
            $table->foreign(['tenant_id', 'program_id'], 'occurrences_tenant_program_fk')
                ->references(['tenant_id', 'id'])->on('programs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'property_id'], 'occurrences_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
        });

        Schema::create('allocations', function (Blueprint $table) {
            $this->tenantUuid($table);
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('resource_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('service_occurrence_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 32)->default('tentative');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();
            $table->index(['tenant_id', 'resource_id', 'starts_at', 'ends_at'], 'allocations_resource_interval_idx');
            $table->index(['tenant_id', 'service_occurrence_id', 'status'], 'allocations_occurrence_idx');
            $table->foreign(['tenant_id', 'reservation_id'], 'allocations_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'resource_id'], 'allocations_tenant_resource_fk')
                ->references(['tenant_id', 'id'])->on('resources');
            $table->foreign(['tenant_id', 'service_occurrence_id'], 'allocations_tenant_occurrence_fk')
                ->references(['tenant_id', 'id'])->on('service_occurrences');
        });

        Schema::create('proposals', function (Blueprint $table) {
            $this->tenantUuid($table);
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 32)->default('draft');
            $table->char('currency', 3);
            $table->bigInteger('total_minor')->default(0);
            $table->json('snapshot');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'reservation_id', 'version']);
            $table->foreign(['tenant_id', 'reservation_id'], 'proposals_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations')->cascadeOnDelete();
        });

        Schema::create('payments', function (Blueprint $table) {
            $this->tenantUuid($table);
            $table->foreignUuid('reservation_id')->constrained()->restrictOnDelete();
            $table->string('status', 32)->default('pending');
            $table->string('method', 40);
            $table->string('provider')->nullable();
            $table->string('provider_reference')->nullable();
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->timestampTz('processed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'provider', 'provider_reference'], 'payments_provider_reference_unique');
            $table->index(['tenant_id', 'reservation_id', 'status']);
            $table->foreign(['tenant_id', 'reservation_id'], 'payments_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations')->restrictOnDelete();
        });

        Schema::create('deposits', function (Blueprint $table) {
            $this->tenantUuid($table);
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->default('due');
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'reservation_id', 'status']);
            $table->foreign(['tenant_id', 'reservation_id'], 'deposits_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'payment_id'], 'deposits_tenant_payment_fk')
                ->references(['tenant_id', 'id'])->on('payments');
        });

        Schema::create('folio_lines', function (Blueprint $table) {
            $this->tenantUuid($table);
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->string('description');
            $table->decimal('quantity', 10, 3)->default(1);
            $table->bigInteger('unit_amount_minor');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->timestampTz('posted_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'reservation_id', 'posted_at']);
            $table->foreign(['tenant_id', 'reservation_id'], 'folio_lines_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'payment_id'], 'folio_lines_tenant_payment_fk')
                ->references(['tenant_id', 'id'])->on('payments');
        });

        Schema::create('operational_tasks', function (Blueprint $table) {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('todo');
            $table->string('priority', 16)->default('normal');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'property_id', 'status', 'due_at'], 'tasks_work_queue_idx');
            $table->foreign(['tenant_id', 'property_id'], 'tasks_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'reservation_id'], 'tasks_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations');
        });

        Schema::create('automation_rules', function (Blueprint $table) {
            $this->tenantUuid($table);
            $table->string('name');
            $table->string('trigger');
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_ran_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'trigger', 'is_active']);
        });

        Schema::create('communications', function (Blueprint $table) {
            $this->tenantUuid($table);
            $table->foreignUuid('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 32);
            $table->string('direction', 16);
            $table->string('status', 32);
            $table->string('subject')->nullable();
            $table->text('body');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'reservation_id', 'created_at']);
            $table->foreign(['tenant_id', 'guest_id'], 'communications_tenant_guest_fk')
                ->references(['tenant_id', 'id'])->on('guests');
            $table->foreign(['tenant_id', 'reservation_id'], 'communications_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations');
        });

        Schema::create('surveys', function (Blueprint $table) {
            $this->tenantUuid($table);
            $table->foreignUuid('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 50);
            $table->unsignedTinyInteger('score')->nullable();
            $table->json('answers')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('responded_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'kind', 'responded_at']);
            $table->foreign(['tenant_id', 'guest_id'], 'surveys_tenant_guest_fk')
                ->references(['tenant_id', 'id'])->on('guests');
            $table->foreign(['tenant_id', 'reservation_id'], 'surveys_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations');
        });

        Schema::create('audits', function (Blueprint $table) {
            $this->tenantUuid($table);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 60);
            $table->string('auditable_type');
            $table->uuid('auditable_id');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'auditable_type', 'auditable_id'], 'audits_subject_idx');
        });

        Schema::create('outbox', function (Blueprint $table) {
            $this->tenantUuid($table);
            $table->string('aggregate_type');
            $table->uuid('aggregate_id');
            $table->string('event_type');
            $table->json('payload');
            $table->timestampTz('occurred_at');
            $table->timestampTz('available_at');
            $table->timestampTz('published_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->index(['published_at', 'available_at']);
            $table->index(['tenant_id', 'aggregate_type', 'aggregate_id'], 'outbox_aggregate_idx');
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $this->tenantUuid($table);
            $table->string('key', 128);
            $table->string('command', 160);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestampTz('expires_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
            $table->index(['tenant_id', 'expires_at']);
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('outbox');
        Schema::dropIfExists('audits');
        Schema::dropIfExists('surveys');
        Schema::dropIfExists('communications');
        Schema::dropIfExists('automation_rules');
        Schema::dropIfExists('operational_tasks');
        Schema::dropIfExists('folio_lines');
        Schema::dropIfExists('deposits');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('proposals');
        Schema::dropIfExists('allocations');
        Schema::dropIfExists('service_occurrences');
        Schema::dropIfExists('reservation_guests');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('resource_blocks');
        Schema::dropIfExists('resources');
        Schema::dropIfExists('programs');
        Schema::dropIfExists('guests');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('properties');
        Schema::dropIfExists('tenants');
    }

    private function tenantUuid(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
        $table->unique(['tenant_id', 'id']);
    }
};
