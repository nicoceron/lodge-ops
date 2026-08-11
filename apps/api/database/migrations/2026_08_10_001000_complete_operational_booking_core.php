<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->string('display_color', 7)->default('#2563EB')->after('description');
            $table->boolean('requires_accommodation')->default(false)->after('display_color');
        });

        Schema::table('resources', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('property_id')->constrained('users')->nullOnDelete();
            $table->boolean('is_buyout')->default(false)->after('capacity');
            $table->index(['tenant_id', 'user_id'], 'resources_tenant_user_idx');
        });

        Schema::table('reservations', function (Blueprint $table): void {
            $table->foreignUuid('program_id')->nullable()->after('property_id')->constrained('programs')->nullOnDelete();
            $table->index(['tenant_id', 'program_id', 'starts_at'], 'reservations_program_interval_idx');
            $table->foreign(['tenant_id', 'program_id'], 'reservations_tenant_program_fk')
                ->references(['tenant_id', 'id'])->on('programs');
        });

        Schema::create('program_resource_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id');
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type', 32);
            $table->unsignedInteger('minimum_quantity')->default(1);
            $table->unsignedInteger('guests_per_resource')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('languages')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'program_id', 'sort_order'], 'program_requirements_order_idx');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'program_id'], 'program_requirements_tenant_program_fk')
                ->references(['tenant_id', 'id'])->on('programs')->cascadeOnDelete();
        });

        Schema::create('program_task_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id');
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('assignee_role', 32)->nullable();
            $table->string('priority', 16)->default('normal');
            $table->integer('due_offset_minutes')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'program_id', 'is_active', 'sort_order'], 'program_task_templates_active_idx');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'program_id'], 'program_tasks_tenant_program_fk')
                ->references(['tenant_id', 'id'])->on('programs')->cascadeOnDelete();
        });

        Schema::table('operational_tasks', function (Blueprint $table): void {
            $table->foreignUuid('program_task_template_id')->nullable()->after('reservation_id')
                ->constrained('program_task_templates')->nullOnDelete();
            $table->unique(
                ['tenant_id', 'reservation_id', 'program_task_template_id'],
                'operational_tasks_reservation_template_unique',
            );
            $table->foreign(
                ['tenant_id', 'program_task_template_id'],
                'operational_tasks_tenant_template_fk',
            )->references(['tenant_id', 'id'])->on('program_task_templates');
        });

        Schema::table('deposits', function (Blueprint $table): void {
            $table->string('schedule_type', 32)->nullable()->after('reservation_id');
            $table->unique(['tenant_id', 'reservation_id', 'schedule_type'], 'deposits_reservation_schedule_unique');
        });

        Schema::create('reservation_status_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id');
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->timestampTz('changed_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'reservation_id', 'changed_at'], 'reservation_status_history_idx');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'reservation_id'], 'status_history_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations')->cascadeOnDelete();
        });

        DB::table('resources')->orderBy('id')->each(function (object $resource): void {
            $attributes = json_decode((string) ($resource->attributes ?? '{}'), true);
            if (is_array($attributes) && filter_var($attributes['buyout'] ?? false, FILTER_VALIDATE_BOOL)) {
                DB::table('resources')->where('id', $resource->id)->update(['is_buyout' => true]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_status_histories');

        Schema::table('deposits', function (Blueprint $table): void {
            $table->dropUnique('deposits_reservation_schedule_unique');
            $table->dropColumn('schedule_type');
        });

        Schema::table('operational_tasks', function (Blueprint $table): void {
            DB::getDriverName() === 'sqlite'
                ? $table->dropForeign(['tenant_id', 'program_task_template_id'])
                : $table->dropForeign('operational_tasks_tenant_template_fk');
            $table->dropForeign(['program_task_template_id']);
            $table->dropUnique('operational_tasks_reservation_template_unique');
            $table->dropColumn('program_task_template_id');
        });

        Schema::dropIfExists('program_task_templates');
        Schema::dropIfExists('program_resource_requirements');

        Schema::table('reservations', function (Blueprint $table): void {
            DB::getDriverName() === 'sqlite'
                ? $table->dropForeign(['tenant_id', 'program_id'])
                : $table->dropForeign('reservations_tenant_program_fk');
            $table->dropForeign(['program_id']);
            $table->dropIndex('reservations_program_interval_idx');
            $table->dropColumn('program_id');
        });

        Schema::table('resources', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropIndex('resources_tenant_user_idx');
            $table->dropColumn(['user_id', 'is_buyout']);
        });

        Schema::table('programs', function (Blueprint $table): void {
            $table->dropColumn(['display_color', 'requires_accommodation']);
        });
    }
};
