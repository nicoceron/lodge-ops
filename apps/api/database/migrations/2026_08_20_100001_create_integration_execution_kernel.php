<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const GLOBAL_SCOPE = '00000000-0000-0000-0000-000000000000';

    /** @var list<array{tenant_id:string,connection_id:string}> */
    private array $legacyEndpointKeys = [];

    /** @var list<array{tenant_id:string,connection_id:string,identity:array<string,mixed>}> */
    private array $identityConflicts = [];

    public function up(): void
    {
        $this->legacyEndpointKeys = [];
        $this->identityConflicts = [];

        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->foreignUuid('property_id')->nullable()->after('tenant_id')->constrained()->restrictOnDelete();
            $table->uuid('property_scope_key')->default(self::GLOBAL_SCOPE)->after('property_id');
            $table->string('provider', 80)->nullable()->after('type');
            $table->string('product', 80)->nullable()->after('provider');
            $table->string('external_account_id', 160)->nullable()->after('product');
            $table->string('environment', 24)->default('sandbox')->after('external_account_id');
            $table->json('capabilities')->nullable()->after('configuration');
            $table->unsignedInteger('configuration_version')->default(1)->after('capabilities');
            $table->unsignedInteger('webhook_key_version')->default(0)->after('payment_webhook_key');
            $table->text('legacy_endpoint_key_ciphertext')->nullable()->after('webhook_key_version');
            $table->boolean('is_enabled')->default(false)->after('status');
            $table->timestampTz('revoked_at')->nullable()->after('is_enabled');
            $table->timestampTz('last_success_at')->nullable()->after('last_synced_at');
            $table->timestampTz('last_error_at')->nullable()->after('last_success_at');
            $table->string('health_status', 24)->default('untested')->after('last_error');
            $table->unsignedBigInteger('lag_seconds')->nullable()->after('health_status');
            $table->timestampTz('last_event_at')->nullable()->after('lag_seconds');
            $table->unsignedInteger('circuit_failure_count')->default(0)->after('last_event_at');
            $table->timestampTz('circuit_opened_at')->nullable()->after('circuit_failure_count');
            $table->timestampTz('throttled_until')->nullable()->after('circuit_opened_at');
        });

        $this->backfillConnections();
        $this->preflightCanonicalIdentities();

        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->string('provider', 80)->nullable(false)->change();
            $table->string('product', 80)->nullable(false)->change();
            $table->string('external_account_id', 160)->nullable(false)->change();
            $table->unique(
                ['tenant_id', 'provider', 'product', 'external_account_id', 'environment', 'property_scope_key'],
                'integration_connections_runtime_identity_unique',
            );
            $table->foreign(['tenant_id', 'property_id'], 'integration_connections_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
            $table->index(['tenant_id', 'health_status', 'is_enabled'], 'integration_connections_health_idx');
        });

        Schema::create('integration_endpoint_keys', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('integration_connection_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->char('key_hash', 64)->unique();
            $table->timestampTz('valid_from');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'integration_connection_id', 'version'], 'integration_endpoint_keys_version_unique');
            $table->foreign(['tenant_id', 'integration_connection_id'], 'integration_endpoint_keys_tenant_connection_fk')
                ->references(['tenant_id', 'id'])->on('integration_connections')->restrictOnDelete();
        });

        $this->backfillEndpointKeys();

        Schema::create('integration_connection_capabilities', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('integration_connection_id')->constrained()->restrictOnDelete();
            $table->string('capability', 80);
            $table->string('direction', 16);
            $table->string('state', 24)->default('disabled');
            $table->json('configuration')->nullable();
            $table->unsignedInteger('configuration_version')->default(1);
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('last_error_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'integration_connection_id', 'capability', 'direction'], 'integration_capabilities_identity_unique');
            $table->foreign(['tenant_id', 'integration_connection_id'], 'integration_capabilities_tenant_connection_fk')
                ->references(['tenant_id', 'id'])->on('integration_connections')->restrictOnDelete();
        });

        Schema::create('integration_mappings', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('integration_connection_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('property_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('property_scope_key')->default(self::GLOBAL_SCOPE);
            $table->string('capability', 80);
            $table->string('direction', 16);
            $table->string('local_entity_type', 100);
            $table->string('local_key', 180);
            $table->string('external_entity_type', 100);
            $table->string('external_key', 180);
            $table->unsignedInteger('transform_version');
            $table->string('conflict_state', 24)->default('clear');
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_until')->nullable();
            $table->char('facts_checksum', 64);
            $table->json('safe_facts')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'integration_connection_id', 'property_scope_key', 'direction', 'external_entity_type', 'external_key', 'transform_version'], 'integration_mappings_external_unique');
            $table->foreign(['tenant_id', 'integration_connection_id'], 'integration_mappings_tenant_connection_fk')
                ->references(['tenant_id', 'id'])->on('integration_connections')->restrictOnDelete();
            $table->foreign(['tenant_id', 'property_id'], 'integration_mappings_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
        });

        Schema::create('integration_sync_cursors', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('integration_connection_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('property_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('property_scope_key')->default(self::GLOBAL_SCOPE);
            $table->string('capability', 80);
            $table->string('direction', 16);
            $table->json('checkpoint')->nullable();
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampTz('committed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'integration_connection_id', 'capability', 'direction', 'property_scope_key'], 'integration_cursors_scoped_unique');
            $table->foreign(['tenant_id', 'integration_connection_id'], 'integration_cursors_tenant_connection_fk')
                ->references(['tenant_id', 'id'])->on('integration_connections')->restrictOnDelete();
            $table->foreign(['tenant_id', 'property_id'], 'integration_cursors_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
        });
        Schema::create('integration_sync_runs', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('integration_connection_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('property_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('capability', 80);
            $table->string('direction', 16);
            $table->string('trigger', 24);
            $table->string('status', 24)->default('queued');
            $table->string('correlation_id', 128);
            $table->string('idempotency_key', 160);
            $table->json('starting_checkpoint')->nullable();
            $table->json('pending_checkpoint')->nullable();
            $table->boolean('pending_has_more')->default(false);
            $table->boolean('page_in_progress')->default(false);
            $table->unsignedInteger('page_number')->default(0);
            $table->unsignedInteger('attempt')->default(0);
            $table->string('claim_token', 64)->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('dead_letter_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'integration_connection_id', 'idempotency_key'], 'integration_runs_idempotency_unique');
            $table->index(['tenant_id', 'status', 'created_at'], 'integration_runs_work_idx');
            $table->foreign(['tenant_id', 'integration_connection_id'], 'integration_runs_tenant_connection_fk')
                ->references(['tenant_id', 'id'])->on('integration_connections')->restrictOnDelete();
            $table->foreign(['tenant_id', 'property_id'], 'integration_runs_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
        });

        Schema::create('integration_sync_run_items', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('integration_sync_run_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('property_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('page_number');
            $table->string('external_key', 180);
            $table->string('local_key', 180)->nullable();
            $table->char('payload_checksum', 64);
            $table->json('safe_payload')->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempt')->default(0);
            $table->string('idempotency_key', 160);
            $table->unsignedInteger('http_status')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->char('request_checksum', 64)->nullable();
            $table->char('response_checksum', 64)->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('available_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'integration_sync_run_id', 'external_key'], 'integration_run_items_identity_unique');
            $table->index(['tenant_id', 'idempotency_key'], 'integration_run_items_idempotency_idx');
            $table->index(['tenant_id', 'status', 'available_at'], 'integration_run_items_work_idx');
            $table->foreign(['tenant_id', 'integration_sync_run_id'], 'integration_run_items_tenant_run_fk')
                ->references(['tenant_id', 'id'])->on('integration_sync_runs')->restrictOnDelete();
            $table->foreign(['tenant_id', 'property_id'], 'integration_run_items_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
        });

        Schema::create('integration_events', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('integration_connection_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('property_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('capability', 80);
            $table->string('external_id', 180);
            $table->string('event_type', 120);
            $table->string('external_version', 80)->default('1');
            $table->char('raw_checksum', 64);
            $table->json('safe_snapshot')->nullable();
            $table->string('disposition', 24)->default('received');
            $table->unsignedInteger('attempt')->default(0);
            $table->timestampTz('occurred_at')->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'integration_connection_id', 'external_id', 'event_type', 'external_version'], 'integration_events_external_unique');
            $table->unique(['tenant_id', 'integration_connection_id', 'raw_checksum'], 'integration_events_checksum_unique');
            $table->index(['tenant_id', 'disposition', 'received_at'], 'integration_events_work_idx');
            $table->foreign(['tenant_id', 'integration_connection_id'], 'integration_events_tenant_connection_fk')
                ->references(['tenant_id', 'id'])->on('integration_connections')->restrictOnDelete();
            $table->foreign(['tenant_id', 'property_id'], 'integration_events_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
        });

        Schema::create('integration_dead_letters', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('integration_connection_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('property_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('integration_sync_run_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('integration_event_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason_code', 80);
            $table->text('safe_error');
            $table->string('status', 24)->default('open');
            $table->unsignedInteger('replay_count')->default(0);
            $table->timestampTz('last_replayed_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'integration_sync_run_item_id'], 'integration_dead_letters_item_unique');
            $table->unique(['tenant_id', 'integration_event_id'], 'integration_dead_letters_event_unique');
            $table->index(['tenant_id', 'status', 'created_at'], 'integration_dead_letters_work_idx');
            $table->foreign(['tenant_id', 'integration_connection_id'], 'integration_dead_letters_tenant_connection_fk')
                ->references(['tenant_id', 'id'])->on('integration_connections')->restrictOnDelete();
            $table->foreign(['tenant_id', 'property_id'], 'integration_dead_letters_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
        });

        Schema::create('integration_reconciliations', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('integration_connection_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('property_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 80);
            $table->string('external_key', 180)->nullable();
            $table->string('local_key', 180)->nullable();
            $table->string('status', 24)->default('open');
            $table->string('reason_code', 80);
            $table->json('safe_facts')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'created_at'], 'integration_reconciliations_work_idx');
            $table->foreign(['tenant_id', 'integration_connection_id'], 'integration_reconciliations_tenant_connection_fk')
                ->references(['tenant_id', 'id'])->on('integration_connections')->restrictOnDelete();
            $table->foreign(['tenant_id', 'property_id'], 'integration_reconciliations_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
        });

        Schema::create('integration_operations', function (Blueprint $table): void {
            $this->tenantUuid($table);
            $table->foreignUuid('integration_connection_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operation', 80);
            $table->char('idempotency_key_hash', 64)->nullable();
            $table->text('reason');
            $table->json('safe_facts')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'integration_connection_id', 'operation', 'idempotency_key_hash'], 'integration_operations_idempotency_unique');
            $table->foreign(['tenant_id', 'integration_connection_id'], 'integration_operations_tenant_connection_fk')
                ->references(['tenant_id', 'id'])->on('integration_connections')->restrictOnDelete();
        });

        $this->recordLegacyEndpointReconciliations();
        $this->recordIdentityConflictReconciliations();
    }

    public function down(): void
    {
        $this->prepareEndpointHashesForRollback();

        Schema::dropIfExists('integration_operations');
        Schema::dropIfExists('integration_reconciliations');
        Schema::dropIfExists('integration_dead_letters');
        Schema::dropIfExists('integration_events');
        Schema::dropIfExists('integration_sync_run_items');
        Schema::dropIfExists('integration_sync_runs');
        Schema::dropIfExists('integration_sync_cursors');
        Schema::dropIfExists('integration_mappings');
        Schema::dropIfExists('integration_connection_capabilities');
        Schema::dropIfExists('integration_endpoint_keys');

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('integration_connections', function (Blueprint $table): void {
                $table->dropForeign(['tenant_id', 'property_id']);
                $table->dropForeign(['property_id']);
            });
        } else {
            Schema::table('integration_connections', function (Blueprint $table): void {
                $table->dropForeign('integration_connections_tenant_property_fk');
                $table->dropForeign(['property_id']);
            });
        }

        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->dropUnique('integration_connections_runtime_identity_unique');
            $table->dropIndex('integration_connections_health_idx');
            $table->dropColumn([
                'property_id', 'property_scope_key', 'provider', 'product', 'external_account_id', 'environment',
                'capabilities', 'configuration_version', 'webhook_key_version', 'legacy_endpoint_key_ciphertext', 'is_enabled', 'revoked_at',
                'last_success_at', 'last_error_at', 'health_status', 'lag_seconds', 'last_event_at',
                'circuit_failure_count', 'circuit_opened_at', 'throttled_until',
            ]);
        });
    }

    private function backfillConnections(): void
    {
        foreach (DB::table('integration_connections')->orderBy('id')->get() as $connection) {
            $configuration = is_string($connection->configuration)
                ? (json_decode($connection->configuration, true) ?: [])
                : ((array) $connection->configuration);
            $provider = trim((string) ($configuration['provider'] ?? $connection->type));
            $product = trim((string) ($configuration['product'] ?? match ($provider) {
                'mercado_pago' => 'checkout_pro',
                default => $connection->type,
            }));
            $account = trim((string) ($configuration['provider_account'] ?? $configuration['external_account_id'] ?? $connection->name));
            $environment = trim((string) ($configuration['environment'] ?? 'sandbox'));
            $propertyId = $configuration['property_id'] ?? null;
            $storedKey = is_string($connection->payment_webhook_key ?? null) && $connection->payment_webhook_key !== ''
                ? $connection->payment_webhook_key
                : (is_string($configuration['webhook_key'] ?? null) ? $configuration['webhook_key'] : null);
            $rollbackHash = $configuration['webhook_endpoint_key_sha256'] ?? null;
            $configurationRaw = is_string($configuration['webhook_key'] ?? null) && $configuration['webhook_key'] !== ''
                ? $configuration['webhook_key']
                : null;
            $storedIsHash = is_string($storedKey) && preg_match('/^[a-f0-9]{64}$/', $storedKey) === 1
                && is_string($rollbackHash) && hash_equals($storedKey, $rollbackHash);
            $rawKey = $configurationRaw ?? ($storedIsHash ? null : $storedKey);
            $keyHash = $rawKey === null ? ($storedIsHash ? $storedKey : null) : hash('sha256', $rawKey);
            unset($configuration['webhook_key']);
            unset($configuration['webhook_endpoint_key_sha256']);
            foreach (['provider', 'product', 'provider_account', 'external_account_id', 'environment', 'property_id'] as $identityKey) {
                unset($configuration[$identityKey]);
            }

            if ($storedKey !== null) {
                $this->legacyEndpointKeys[] = ['tenant_id' => $connection->tenant_id, 'connection_id' => $connection->id];
            }

            DB::table('integration_connections')->where('id', $connection->id)->update([
                'property_id' => $propertyId,
                'property_scope_key' => $propertyId ?: self::GLOBAL_SCOPE,
                'provider' => $provider !== '' ? $provider : 'unassigned',
                'product' => $product !== '' ? $product : $connection->type,
                'external_account_id' => $account !== '' ? $account : $connection->name,
                'environment' => $environment !== '' ? $environment : 'sandbox',
                'capabilities' => json_encode($connection->type === 'payment' ? ['payment.hosted_checkout'] : [], JSON_THROW_ON_ERROR),
                'payment_webhook_key' => $keyHash,
                'webhook_key_version' => $keyHash === null ? 0 : 1,
                'legacy_endpoint_key_ciphertext' => $rawKey === null ? null : Crypt::encryptString($rawKey),
                'configuration' => json_encode($configuration, JSON_THROW_ON_ERROR),
                'is_enabled' => in_array($connection->status, ['connected', 'configured'], true),
                'health_status' => $connection->last_error === null ? 'untested' : 'degraded',
            ]);
        }
    }

    private function backfillEndpointKeys(): void
    {
        foreach (DB::table('integration_connections')->whereNotNull('payment_webhook_key')->get(['id', 'tenant_id', 'payment_webhook_key']) as $connection) {
            DB::table('integration_endpoint_keys')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $connection->tenant_id,
                'integration_connection_id' => $connection->id,
                'version' => 1,
                'key_hash' => $connection->payment_webhook_key,
                'valid_from' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function prepareEndpointHashesForRollback(): void
    {
        foreach (DB::table('integration_connections')->get([
            'id', 'configuration', 'payment_webhook_key', 'legacy_endpoint_key_ciphertext', 'provider', 'product',
            'external_account_id', 'environment', 'property_id',
        ]) as $connection) {
            $configuration = is_string($connection->configuration)
                ? (json_decode($connection->configuration, true) ?: [])
                : ((array) $connection->configuration);
            $originalConflict = Schema::hasTable('integration_reconciliations')
                ? DB::table('integration_reconciliations')->where('integration_connection_id', $connection->id)
                    ->where('kind', 'canonical_identity_collision')->value('safe_facts')
                : null;
            $originalFacts = is_string($originalConflict) ? (json_decode($originalConflict, true) ?: []) : (array) $originalConflict;
            $configuration['provider'] = $originalFacts['provider'] ?? $connection->provider;
            $configuration['product'] = $originalFacts['product'] ?? $connection->product;
            $configuration['provider_account'] = $originalFacts['external_account_id'] ?? $connection->external_account_id;
            $configuration['environment'] = $originalFacts['environment'] ?? $connection->environment;
            if (($originalFacts['property_id'] ?? $connection->property_id) !== null) {
                $configuration['property_id'] = $originalFacts['property_id'] ?? $connection->property_id;
            }
            $rawKey = null;
            if (is_string($connection->legacy_endpoint_key_ciphertext) && $connection->legacy_endpoint_key_ciphertext !== '') {
                $rawKey = Crypt::decryptString($connection->legacy_endpoint_key_ciphertext);
            }
            if ($rawKey !== null) {
                $configuration['webhook_key'] = $rawKey;
            } else {
                unset($configuration['webhook_key']);
            }
            DB::table('integration_connections')->where('id', $connection->id)->update([
                'configuration' => json_encode($configuration, JSON_THROW_ON_ERROR),
                'payment_webhook_key' => $rawKey,
            ]);
        }
    }

    private function recordLegacyEndpointReconciliations(): void
    {
        foreach ($this->legacyEndpointKeys as $legacy) {
            DB::table('integration_reconciliations')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $legacy['tenant_id'],
                'integration_connection_id' => $legacy['connection_id'],
                'kind' => 'legacy_endpoint_key_rotation',
                'status' => 'open',
                'reason_code' => 'raw_key_removed_from_database',
                'safe_facts' => json_encode(['required_action' => 'Rotate the endpoint key and store the issued value in the configured secret manager.'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function preflightCanonicalIdentities(): void
    {
        $groups = DB::table('integration_connections')
            ->select(['tenant_id', 'provider', 'product', 'external_account_id', 'environment', 'property_scope_key'])
            ->selectRaw('COUNT(*) AS duplicate_count')
            ->groupBy(['tenant_id', 'provider', 'product', 'external_account_id', 'environment', 'property_scope_key'])
            ->havingRaw('COUNT(*) > 1')->get();
        foreach ($groups as $group) {
            $duplicates = DB::table('integration_connections')
                ->where('tenant_id', $group->tenant_id)->where('provider', $group->provider)
                ->where('product', $group->product)->where('external_account_id', $group->external_account_id)
                ->where('environment', $group->environment)->where('property_scope_key', $group->property_scope_key)
                ->orderBy('created_at')->orderBy('id')->get();
            foreach ($duplicates->slice(1) as $duplicate) {
                $identity = [
                    'provider' => $group->provider, 'product' => $group->product,
                    'external_account_id' => $group->external_account_id, 'environment' => $group->environment,
                    'property_id' => $duplicate->property_id,
                ];
                $this->identityConflicts[] = [
                    'tenant_id' => $duplicate->tenant_id, 'connection_id' => $duplicate->id, 'identity' => $identity,
                ];
                DB::table('integration_connections')->where('id', $duplicate->id)->update([
                    'external_account_id' => 'conflict:'.$duplicate->id,
                    'is_enabled' => false,
                    'status' => 'disabled',
                    'last_error' => 'Canonical identity collision requires operator reconciliation.',
                    'health_status' => 'degraded',
                ]);
            }
        }
    }

    private function recordIdentityConflictReconciliations(): void
    {
        foreach ($this->identityConflicts as $conflict) {
            DB::table('integration_reconciliations')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $conflict['tenant_id'],
                'integration_connection_id' => $conflict['connection_id'],
                'kind' => 'canonical_identity_collision',
                'status' => 'open',
                'reason_code' => 'duplicate_legacy_connection_identity',
                'safe_facts' => json_encode($conflict['identity'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function tenantUuid(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
        $table->unique(['tenant_id', 'id']);
    }
};
