<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table): void {
            $table->json('calendar_preferences')->nullable();
        });

        Schema::table('allocations', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(1);
            $table->index(['tenant_id', 'resource_id', 'status', 'starts_at', 'ends_at'], 'allocations_resource_status_interval_idx');
        });

        Schema::table('reservation_guests', function (Blueprint $table): void {
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('operational_preferences')->nullable();
            $table->index(['tenant_id', 'reservation_id', 'sort_order'], 'reservation_guests_order_idx');
        });

        Schema::table('proposals', function (Blueprint $table): void {
            $table->foreignUuid('booking_quote_id')->nullable()->after('reservation_id')
                ->constrained('booking_quotes')->restrictOnDelete();
            $table->string('inquiry_source', 32)->nullable()->after('reference');
            $table->string('send_intent_key', 160)->nullable();
            $table->unique(['tenant_id', 'send_intent_key'], 'proposals_send_intent_unique');
            $table->foreign(['tenant_id', 'booking_quote_id'], 'proposals_tenant_booking_quote_fk')
                ->references(['tenant_id', 'id'])->on('booking_quotes')->restrictOnDelete();
        });

        Schema::create('checklist_templates', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('program_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('role', 32);
            $table->string('state', 16)->default('draft');
            $table->unsignedInteger('latest_version')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'property_id', 'program_id', 'role', 'state'], 'checklist_templates_lookup_idx');
            $table->foreign(['tenant_id', 'property_id'], 'checklist_templates_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'program_id'], 'checklist_templates_tenant_program_fk')
                ->references(['tenant_id', 'id'])->on('programs')->cascadeOnDelete();
        });

        Schema::create('checklist_template_versions', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('checklist_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('state', 16)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'checklist_template_id', 'version'], 'checklist_template_versions_unique');
            $table->foreign(['tenant_id', 'checklist_template_id'], 'checklist_versions_tenant_template_fk')
                ->references(['tenant_id', 'id'])->on('checklist_templates')->cascadeOnDelete();
        });

        Schema::create('checklist_template_items', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('checklist_template_version_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 16)->default('normal');
            $table->integer('due_offset_minutes')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'checklist_template_version_id', 'sort_order'], 'checklist_items_order_idx');
            $table->foreign(['tenant_id', 'checklist_template_version_id'], 'checklist_items_tenant_version_fk')
                ->references(['tenant_id', 'id'])->on('checklist_template_versions')->cascadeOnDelete();
        });

        Schema::create('reservation_checklist_exceptions', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('checklist_template_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation', 16);
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('priority', 16)->nullable();
            $table->integer('due_offset_minutes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'reservation_id', 'sort_order'], 'reservation_checklist_exceptions_order_idx');
            $table->foreign(['tenant_id', 'reservation_id'], 'checklist_exceptions_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'checklist_template_item_id'], 'checklist_exceptions_tenant_item_fk')
                ->references(['tenant_id', 'id'])->on('checklist_template_items')->nullOnDelete();
        });

        Schema::table('operational_tasks', function (Blueprint $table): void {
            $table->foreignUuid('checklist_template_version_id')->nullable()->after('program_task_template_id')
                ->constrained()->nullOnDelete();
            $table->foreignUuid('checklist_template_item_id')->nullable()->after('checklist_template_version_id')
                ->constrained()->nullOnDelete();
            $table->foreignUuid('reservation_checklist_exception_id')->nullable()->after('checklist_template_item_id')
                ->constrained()->nullOnDelete();
            $table->unsignedInteger('generation')->default(1);
            $table->unsignedInteger('revision')->default(1);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestampTz('reopened_at')->nullable();
            $table->timestampTz('escalated_at')->nullable();
            $table->text('escalation_reason')->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->foreignUuid('superseded_by_id')->nullable()->constrained('operational_tasks')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->unique(['tenant_id', 'reservation_id', 'checklist_template_item_id', 'generation'], 'operational_tasks_checklist_generation_unique');
            $table->index(['tenant_id', 'status', 'due_at', 'escalated_at'], 'operational_tasks_exception_queue_idx');
        });

        Schema::create('operational_task_events', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('operational_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 32);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->text('reason')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestamps();
            $table->index(['tenant_id', 'operational_task_id', 'occurred_at'], 'operational_task_events_timeline_idx');
            $table->foreign(['tenant_id', 'operational_task_id'], 'task_events_tenant_task_fk')
                ->references(['tenant_id', 'id'])->on('operational_tasks')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        $supportsNamedForeignKeyDrops = Schema::getConnection()->getDriverName() !== 'sqlite';
        Schema::dropIfExists('operational_task_events');
        Schema::table('operational_tasks', function (Blueprint $table): void {
            $table->dropUnique('operational_tasks_checklist_generation_unique');
            $table->dropIndex('operational_tasks_exception_queue_idx');
            $table->dropForeign(['checklist_template_version_id']);
            $table->dropForeign(['checklist_template_item_id']);
            $table->dropForeign(['reservation_checklist_exception_id']);
            $table->dropForeign(['superseded_by_id']);
            $table->dropColumn([
                'checklist_template_version_id', 'checklist_template_item_id', 'reservation_checklist_exception_id',
                'generation', 'revision', 'started_at', 'failed_at', 'failure_reason', 'reopened_at',
                'escalated_at', 'escalation_reason', 'superseded_at', 'superseded_by_id', 'cancellation_reason',
            ]);
        });
        Schema::dropIfExists('reservation_checklist_exceptions');
        Schema::dropIfExists('checklist_template_items');
        Schema::dropIfExists('checklist_template_versions');
        Schema::dropIfExists('checklist_templates');
        Schema::table('proposals', function (Blueprint $table) use ($supportsNamedForeignKeyDrops): void {
            $table->dropUnique('proposals_send_intent_unique');
            if ($supportsNamedForeignKeyDrops) {
                $table->dropForeign('proposals_tenant_booking_quote_fk');
            } else {
                $table->dropForeign(['tenant_id', 'booking_quote_id']);
            }
            $table->dropForeign(['booking_quote_id']);
            $table->dropColumn(['booking_quote_id', 'inquiry_source', 'send_intent_key']);
        });
        Schema::table('reservation_guests', function (Blueprint $table): void {
            $table->dropIndex('reservation_guests_order_idx');
            $table->dropColumn(['sort_order', 'operational_preferences']);
        });
        Schema::table('allocations', function (Blueprint $table): void {
            $table->dropIndex('allocations_resource_status_interval_idx');
            $table->dropColumn('revision');
        });
        Schema::table('memberships', function (Blueprint $table): void {
            $table->dropColumn('calendar_preferences');
        });
    }

    private function tenantUuid(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
        $table->unique(['tenant_id', 'id']);
    }
};
