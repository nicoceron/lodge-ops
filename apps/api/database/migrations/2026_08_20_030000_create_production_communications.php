<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isPostgres = DB::connection()->getDriverName() === 'pgsql';
        Schema::create('communication_provider_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('account_id', 160);
            $table->char('endpoint_key_hash', 64)->unique();
            $table->string('secret_ref', 190);
            $table->json('webhook_secret_refs');
            $table->string('from_email', 254);
            $table->string('from_name', 160);
            $table->string('reply_to_email', 254)->nullable();
            $table->json('allowed_sender_domains');
            $table->boolean('is_enabled')->default(false);
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'property_id', 'provider'], 'communication_provider_property_unique');
            $table->foreign(['tenant_id', 'property_id'], 'communication_provider_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
        });

        Schema::table('communications', function (Blueprint $table) use ($isPostgres): void {
            $table->foreignUuid('property_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
            $table->string('purpose', 32)->default('transactional')->after('direction');
            $table->string('template_key', 80)->nullable()->after('purpose');
            $table->unsignedInteger('template_version')->nullable()->after('template_key');
            $table->string('locale', 12)->nullable()->after('template_version');
            $table->char('content_checksum', 64)->nullable()->after('body');
            $table->timestampTz('accepted_at')->nullable()->after('sent_at');
            $table->timestampTz('failed_at')->nullable()->after('delivered_at');
            $table->index(['tenant_id', 'property_id', 'status'], 'communications_property_status_idx');
            if ($isPostgres) {
                $table->foreign(['tenant_id', 'property_id'], 'communications_tenant_property_fk')
                    ->references(['tenant_id', 'id'])->on('properties')->nullOnDelete();
            }
        });

        Schema::table('delivery_attempts', function (Blueprint $table): void {
            $table->foreignUuid('communication_provider_connection_id')->nullable()->after('communication_id')->constrained()->nullOnDelete();
            $table->string('provider_account_id', 160)->nullable()->after('provider');
            $table->string('provider_message_id', 190)->nullable()->after('provider_reference');
            $table->char('request_checksum', 64)->nullable()->after('idempotency_key');
            $table->string('kind', 24)->default('initial')->after('status');
            $table->string('retry_state', 32)->default('none')->after('attempt');
            $table->string('error_code', 80)->nullable()->after('response');
            $table->text('safe_error')->nullable()->after('error_code');
            $table->timestampTz('accepted_at')->nullable()->after('attempted_at');
            $table->timestampTz('sent_at')->nullable()->after('accepted_at');
            $table->timestampTz('delivered_at')->nullable()->after('sent_at');
            $table->timestampTz('failed_at')->nullable()->after('delivered_at');
            $table->timestampTz('reconcile_after')->nullable()->after('failed_at');
            $table->index(['tenant_id', 'provider', 'provider_message_id'], 'delivery_attempt_provider_message_idx');
            $table->index(['tenant_id', 'status', 'retry_state'], 'delivery_attempt_operations_idx');
        });

        Schema::create('communication_delivery_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('communication_provider_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('delivery_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 40);
            $table->string('provider_account_id', 160);
            $table->string('provider_event_id', 190);
            $table->string('provider_message_id', 190)->nullable();
            $table->string('type', 48);
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->char('raw_body_checksum', 64);
            $table->json('normalized_payload');
            $table->string('processing_state', 32)->default('pending');
            $table->text('processing_error')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['communication_provider_connection_id', 'provider_event_id'], 'communication_delivery_event_unique');
            $table->index(['tenant_id', 'processing_state', 'received_at'], 'communication_delivery_event_queue_idx');
        });

        Schema::create('communication_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('property_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scope_key', 80);
            $table->string('channel', 32);
            $table->string('purpose', 32);
            $table->boolean('is_allowed');
            $table->string('source', 80);
            $table->string('policy_version', 80);
            $table->timestampTz('recorded_at');
            $table->timestampTz('withdrawn_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'scope_key', 'channel', 'purpose'], 'communication_preference_scope_unique');
            $table->foreign(['tenant_id', 'property_id'], 'communication_preferences_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'guest_id'], 'communication_preferences_tenant_guest_fk')
                ->references(['tenant_id', 'id'])->on('guests')->nullOnDelete();
        });

        Schema::table('communication_suppressions', function (Blueprint $table): void {
            $table->foreignUuid('property_id')->nullable()->after('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('source', 80)->default('manual')->after('reason');
            $table->string('provider_event_id', 190)->nullable()->after('source');
            $table->timestampTz('suppressed_at')->nullable()->after('provider_event_id');
            $table->timestampTz('lifted_at')->nullable()->after('expires_at');
            $table->foreignId('lifted_by')->nullable()->after('lifted_at')->constrained('users')->nullOnDelete();
            $table->text('lift_reason')->nullable()->after('lifted_by');
            $table->index(['tenant_id', 'property_id', 'channel', 'recipient_hash'], 'communication_suppression_lookup_idx');
        });

        Schema::create('reservation_milestone_occurrences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->unsignedInteger('reservation_revision')->default(1);
            $table->string('rule_version', 40);
            $table->string('policy_version', 80);
            $table->string('timezone', 64);
            $table->timestamp('target_local');
            $table->timestampTz('target_at');
            $table->string('state', 32)->default('pending');
            $table->uuid('claim_token')->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->string('outbox_id')->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->string('supersession_reason', 160)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'reservation_id', 'key', 'reservation_revision'], 'reservation_occurrence_identity_unique');
            $table->index(['state', 'target_at', 'claimed_at'], 'reservation_occurrence_claim_idx');
            $table->foreign(['tenant_id', 'property_id'], 'reservation_occurrences_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'reservation_id'], 'reservation_occurrences_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations')->cascadeOnDelete();
        });

        Schema::create('scheduler_heartbeats', function (Blueprint $table): void {
            $table->string('name', 100)->primary();
            $table->timestampTz('last_seen_at');
            $table->string('node', 190);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        DB::table('reservation_automation_milestones')
            ->orderBy('id')
            ->each(function (object $legacy): void {
                $reservation = DB::table('reservations')->where('id', $legacy->reservation_id)->first(['property_id', 'revision']);
                $property = $reservation ? DB::table('properties')->where('id', $reservation->property_id)->first(['timezone']) : null;
                if (! $reservation || ! $property) {
                    return;
                }

                DB::table('reservation_milestone_occurrences')->insert([
                    'id' => $legacy->id,
                    'tenant_id' => $legacy->tenant_id,
                    'property_id' => $reservation->property_id,
                    'reservation_id' => $legacy->reservation_id,
                    'key' => $legacy->key,
                    'reservation_revision' => max(1, (int) $reservation->revision),
                    'rule_version' => 'legacy-v1',
                    'policy_version' => 'legacy-v1',
                    'timezone' => $property->timezone,
                    'target_local' => $legacy->occurred_at,
                    'target_at' => $legacy->occurred_at,
                    'state' => 'dispatched',
                    'attempts' => 1,
                    'dispatched_at' => $legacy->occurred_at,
                    'created_at' => $legacy->created_at,
                    'updated_at' => $legacy->updated_at,
                ]);
            });
    }

    public function down(): void
    {
        $isPostgres = DB::connection()->getDriverName() === 'pgsql';
        Schema::dropIfExists('scheduler_heartbeats');
        Schema::dropIfExists('reservation_milestone_occurrences');
        Schema::table('communication_suppressions', function (Blueprint $table): void {
            $table->dropIndex('communication_suppression_lookup_idx');
            $table->dropForeign(['property_id']);
            $table->dropForeign(['lifted_by']);
            $table->dropColumn(['property_id', 'source', 'provider_event_id', 'suppressed_at', 'lifted_at', 'lifted_by', 'lift_reason']);
        });
        Schema::dropIfExists('communication_preferences');
        Schema::dropIfExists('communication_delivery_events');
        Schema::table('delivery_attempts', function (Blueprint $table): void {
            $table->dropIndex('delivery_attempt_provider_message_idx');
            $table->dropIndex('delivery_attempt_operations_idx');
            $table->dropForeign(['communication_provider_connection_id']);
            $table->dropColumn(['communication_provider_connection_id', 'provider_account_id', 'provider_message_id', 'request_checksum', 'kind', 'retry_state', 'error_code', 'safe_error', 'accepted_at', 'sent_at', 'delivered_at', 'failed_at', 'reconcile_after']);
        });
        Schema::table('communications', function (Blueprint $table) use ($isPostgres): void {
            $table->dropIndex('communications_property_status_idx');
            if ($isPostgres) {
                $table->dropForeign('communications_tenant_property_fk');
            }
            $table->dropForeign(['property_id']);
            $table->dropColumn(['property_id', 'purpose', 'template_key', 'template_version', 'locale', 'content_checksum', 'accepted_at', 'failed_at']);
        });
        Schema::dropIfExists('communication_provider_connections');
    }
};
