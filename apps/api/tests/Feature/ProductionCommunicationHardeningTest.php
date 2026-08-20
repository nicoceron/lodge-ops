<?php

namespace Tests\Feature;

use App\Enums\CommunicationPurpose;
use App\Enums\MembershipRole;
use App\Exceptions\CommunicationProviderException;
use App\Jobs\SendCommunication;
use App\Models\Communication;
use App\Models\CommunicationDeliveryEvent;
use App\Models\CommunicationProviderConnection;
use App\Models\CommunicationPurposePolicy;
use App\Models\CommunicationSuppression;
use App\Models\DeliveryAttempt;
use App\Models\Property;
use App\Models\SchedulerHeartbeat;
use App\Services\CommunicationDeliveryService;
use App\Services\Communications\CommunicationOperationsService;
use App\Services\Communications\CommunicationProviderVerificationService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class ProductionCommunicationHardeningTest extends TestCase
{
    use CreatesTenant, DatabaseMigrations;

    public function test_expired_uncertain_outcome_is_committed_as_reconciliation_required_and_cannot_be_retried(): void
    {
        [, $property, $actor] = $this->tenantEnvironment();
        $communication = Communication::query()->create([
            'property_id' => $property->id, 'channel' => 'email', 'direction' => 'outbound',
            'purpose' => 'transactional', 'status' => 'outcome_uncertain', 'subject' => 'Uncertain', 'body' => 'Uncertain',
        ]);
        DeliveryAttempt::query()->create([
            'communication_id' => $communication->id, 'provider' => 'resend', 'status' => 'outcome_uncertain',
            'kind' => 'initial', 'idempotency_key' => 'communication:'.$communication->id, 'attempt' => 1,
            'retry_state' => 'outcome_uncertain', 'attempted_at' => now()->subHours(25), 'reconcile_after' => now()->subHour(),
        ]);

        try {
            app(CommunicationOperationsService::class)->retry($actor, $communication);
            $this->fail('Expired uncertain outcomes must not be manually retried.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('requires reconciliation', $exception->getMessage());
        }

        $this->assertSame('reconciliation_required', $communication->fresh()->status);
        $this->assertDatabaseHas('delivery_attempts', [
            'communication_id' => $communication->id,
            'status' => 'reconciliation_required',
            'retry_state' => 'reconciliation_required',
        ]);
    }

    public function test_near_expiry_uncertain_retry_keeps_the_first_request_deadline(): void
    {
        [, $property] = $this->tenantEnvironment(authenticate: false);
        putenv('COMMUNICATION_WINDOW_KEY=window-test-key');
        CommunicationProviderConnection::query()->create([
            'property_id' => $property->id, 'provider' => 'resend', 'account_id' => 'acct_window',
            'endpoint_key' => 'provider-window-endpoint-key-000000001',
            'secret_ref' => 'env:COMMUNICATION_WINDOW_KEY', 'webhook_secret_refs' => ['env:WINDOW_WEBHOOK'],
            'from_email' => 'mail@mail.example.com', 'from_name' => 'Inn',
            'allowed_sender_domains' => ['mail.example.com'], 'verified_at' => now(), 'is_enabled' => true,
        ]);
        $startedAt = now()->subHours(23)->subMinutes(59)->toImmutable();
        $expiresAt = $startedAt->addHours(24);
        $communication = $this->metadataCommunication($property, 'near-expiry@example.com', 'near-expiry');
        $communication->forceFill([
            'status' => 'retry_pending',
            'delivery_idempotency_started_at' => $startedAt,
            'delivery_idempotency_expires_at' => $expiresAt,
        ])->save();
        DeliveryAttempt::query()->create([
            'communication_id' => $communication->id, 'provider' => 'resend', 'status' => 'retry_pending',
            'kind' => 'initial', 'idempotency_key' => 'communication:'.$communication->id, 'attempt' => 1,
            'retry_state' => 'retry_pending', 'attempted_at' => $startedAt, 'failed_at' => $startedAt,
        ]);
        Http::fake(['api.resend.com/emails' => Factory::failedConnection('socket timeout')]);

        $failed = false;
        try {
            app(CommunicationDeliveryService::class)->deliver($communication);
        } catch (CommunicationProviderException $exception) {
            $this->assertSame('network_timeout', $exception->safeCode);
            $failed = true;
        }

        $this->assertTrue($failed, 'The deterministic provider request must fail at the network boundary.');
        $attempt = DeliveryAttempt::query()->where('communication_id', $communication->id)
            ->where('attempt', 2)->firstOrFail();
        $this->assertSame('outcome_uncertain', $attempt->status);
        $this->assertSame($expiresAt->getTimestamp(), $attempt->reconcile_after->getTimestamp());
        $this->assertSame($startedAt->getTimestamp(), $communication->fresh()->delivery_idempotency_started_at->getTimestamp());
        $this->assertSame($expiresAt->getTimestamp(), $communication->fresh()->delivery_idempotency_expires_at->getTimestamp());
        $this->assertLessThan(120, now()->diffInSeconds($attempt->reconcile_after));
        putenv('COMMUNICATION_WINDOW_KEY');
    }

    public function test_later_retry_pending_attempt_cannot_hide_expired_group_uncertainty_from_manual_operations(): void
    {
        [, $property, $actor] = $this->tenantEnvironment();
        $startedAt = now()->subHours(25)->toImmutable();
        $expiresAt = $startedAt->addHours(24);
        $communication = $this->metadataCommunication($property, 'grouped-operations@example.com', 'grouped-operations');
        $communication->forceFill([
            'status' => 'retry_pending',
            'delivery_idempotency_started_at' => $startedAt,
            'delivery_idempotency_expires_at' => $expiresAt,
        ])->save();
        foreach ([
            [1, 'outcome_uncertain', $startedAt],
            [2, 'retry_pending', $startedAt->addHours(23)],
        ] as [$number, $status, $attemptedAt]) {
            DeliveryAttempt::query()->create([
                'communication_id' => $communication->id, 'provider' => 'resend', 'status' => $status,
                'kind' => $number === 1 ? 'initial' : 'retry',
                'idempotency_key' => 'communication:'.$communication->id, 'attempt' => $number,
                'retry_state' => $status, 'attempted_at' => $attemptedAt,
                'reconcile_after' => $status === 'outcome_uncertain' ? $expiresAt : null,
            ]);
        }

        foreach (['retry', 'newResend'] as $operation) {
            try {
                app(CommunicationOperationsService::class)->{$operation}($actor, $communication->fresh());
                $this->fail("Expired grouped uncertainty must block {$operation}.");
            } catch (DomainException $exception) {
                $this->assertStringContainsString('requires reconciliation', $exception->getMessage());
            }
        }

        $this->assertSame('reconciliation_required', $communication->fresh()->status);
        $this->assertDatabaseHas('delivery_attempts', [
            'communication_id' => $communication->id,
            'attempt' => 1,
            'status' => 'reconciliation_required',
        ]);
        $this->assertSame(1, Communication::query()->where('subject', $communication->subject)->count());
    }

    public function test_delivery_inspects_the_whole_idempotency_group_before_provider_mutation(): void
    {
        [, $property] = $this->tenantEnvironment(authenticate: false);
        putenv('COMMUNICATION_GROUP_KEY=group-test-key');
        CommunicationProviderConnection::query()->create([
            'property_id' => $property->id, 'provider' => 'resend', 'account_id' => 'acct_group',
            'endpoint_key' => 'provider-group-endpoint-key-0000000001',
            'secret_ref' => 'env:COMMUNICATION_GROUP_KEY', 'webhook_secret_refs' => ['env:GROUP_WEBHOOK'],
            'from_email' => 'mail@mail.example.com', 'from_name' => 'Inn',
            'allowed_sender_domains' => ['mail.example.com'], 'verified_at' => now(), 'is_enabled' => true,
        ]);
        $startedAt = now()->subHours(25)->toImmutable();
        $expiresAt = $startedAt->addHours(24);
        $communication = $this->metadataCommunication($property, 'grouped-delivery@example.com', 'grouped-delivery');
        $communication->forceFill([
            'status' => 'retry_pending',
            'delivery_idempotency_started_at' => $startedAt,
            'delivery_idempotency_expires_at' => $expiresAt,
        ])->save();
        foreach ([[1, 'outcome_uncertain'], [2, 'retry_pending']] as [$number, $status]) {
            DeliveryAttempt::query()->create([
                'communication_id' => $communication->id, 'provider' => 'resend', 'status' => $status,
                'kind' => $number === 1 ? 'initial' : 'retry',
                'idempotency_key' => 'communication:'.$communication->id, 'attempt' => $number,
                'retry_state' => $status, 'attempted_at' => $startedAt->addHours($number - 1),
                'reconcile_after' => $status === 'outcome_uncertain' ? $expiresAt : null,
            ]);
        }
        Http::fake(['api.resend.com/emails' => Http::response(['id' => 'must-not-send'], 200)]);

        app(CommunicationDeliveryService::class)->deliver($communication);

        Http::assertNothingSent();
        $this->assertSame('reconciliation_required', $communication->fresh()->status);
        $this->assertSame(2, DeliveryAttempt::query()->where('communication_id', $communication->id)->count());
        putenv('COMMUNICATION_GROUP_KEY');
    }

    public function test_property_and_global_suppressions_are_isolated_and_cover_metadata_recipients(): void
    {
        [, $propertyA] = $this->tenantEnvironment(authenticate: false);
        $propertyB = Property::factory()->create();
        $recipient = 'role-recipient@example.com';
        $hash = hash('sha256', $recipient);
        CommunicationSuppression::query()->create([
            'property_id' => $propertyA->id, 'channel' => 'email', 'recipient_hash' => $hash, 'reason' => 'manual',
        ]);
        Mail::fake();
        $allowed = $this->metadataCommunication($propertyB, $recipient, 'allowed');

        app(CommunicationDeliveryService::class)->deliver($allowed);

        $this->assertSame('sent', $allowed->fresh()->status);
        CommunicationSuppression::query()->create([
            'property_id' => null, 'channel' => 'email', 'recipient_hash' => $hash, 'reason' => 'complaint',
        ]);
        $blocked = $this->metadataCommunication($propertyB, $recipient, 'blocked');
        app(CommunicationDeliveryService::class)->deliver($blocked);
        $this->assertSame('suppressed', $blocked->fresh()->status);
        $this->assertSame(2, CommunicationSuppression::query()->where('recipient_hash', $hash)->count());
        Mail::assertSentCount(1);
    }

    public function test_provider_sender_verification_uses_secret_reference_and_verified_domain_without_exposing_secret(): void
    {
        [, $property, $actor] = $this->tenantEnvironment();
        putenv('COMMUNICATION_VERIFY_KEY=provider-test-key');
        $connection = CommunicationProviderConnection::query()->create([
            'property_id' => $property->id, 'provider' => 'resend', 'account_id' => 'acct_verify',
            'endpoint_key' => 'provider-verification-endpoint-key-00001',
            'secret_ref' => 'env:COMMUNICATION_VERIFY_KEY', 'webhook_secret_refs' => ['env:COMMUNICATION_VERIFY_WEBHOOK'],
            'from_email' => 'reservations@mail.example.com', 'from_name' => 'Inn',
            'allowed_sender_domains' => ['mail.example.com'], 'is_enabled' => false,
        ]);
        Http::fake(['api.resend.com/domains' => Http::response(['data' => [[
            'id' => 'domain_fixture', 'name' => 'mail.example.com', 'status' => 'verified',
        ]]], 200)]);

        app(CommunicationProviderVerificationService::class)->verify($actor, $connection);

        $connection->refresh();
        $this->assertNotNull($connection->verified_at);
        $this->assertSame($actor->id, $connection->verified_by);
        $this->assertSame('domain_fixture', $connection->verification_reference);
        $this->assertSame('env:COMMUNICATION_VERIFY_KEY', $connection->secret_ref);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer provider-test-key'));
        putenv('COMMUNICATION_VERIFY_KEY');
    }

    public function test_horizon_requires_explicit_system_admin_and_health_command_surfaces_stale_heartbeat(): void
    {
        [, , $tenantAdministrator] = $this->tenantEnvironment();
        config()->set('horizon.dashboard_enabled', true);
        $this->assertFalse(Gate::forUser($tenantAdministrator)->allows('viewHorizon'));
        $tenantAdministrator->forceFill(['is_system_admin' => true])->save();
        $this->assertTrue(Gate::forUser($tenantAdministrator->fresh())->allows('viewHorizon'));

        $this->artisan('communications:health')->assertFailed();
        SchedulerHeartbeat::query()->create([
            'name' => 'reservation-milestones', 'last_seen_at' => now(), 'node' => 'test',
        ]);
        $this->artisan('communications:health')->assertSuccessful();
    }

    public function test_fixed_purpose_ledger_is_complete_and_retry_policy_is_role_scoped(): void
    {
        [, $property, $viewer] = $this->tenantEnvironment(MembershipRole::Viewer);
        $this->assertSame(count(CommunicationPurpose::cases()), CommunicationPurposePolicy::query()->where('is_active', true)->count());
        $communication = Communication::query()->create([
            'property_id' => $property->id, 'channel' => 'email', 'direction' => 'outbound',
            'purpose' => 'transactional', 'status' => 'failed', 'subject' => 'Policy', 'body' => 'Policy',
        ]);
        $this->assertFalse($viewer->can('retry', $communication));
        $this->assertFalse($viewer->can('newResend', $communication));
    }

    public function test_restart_after_provider_acceptance_reuses_identity_inside_provider_window(): void
    {
        [, $property] = $this->tenantEnvironment(authenticate: false);
        putenv('COMMUNICATION_RECOVERY_KEY=recovery-test-key');
        $connection = CommunicationProviderConnection::query()->create([
            'property_id' => $property->id, 'provider' => 'resend', 'account_id' => 'acct_recovery',
            'endpoint_key' => 'provider-recovery-endpoint-key-0000001',
            'secret_ref' => 'env:COMMUNICATION_RECOVERY_KEY', 'webhook_secret_refs' => ['env:RECOVERY_WEBHOOK'],
            'from_email' => 'mail@mail.example.com', 'from_name' => 'Inn',
            'allowed_sender_domains' => ['mail.example.com'], 'verified_at' => now(), 'is_enabled' => true,
        ]);
        $communication = $this->metadataCommunication($property, 'recovery@example.com', 'recovery');
        $communication->forceFill(['purpose' => 'transactional', 'status' => 'sending'])->save();
        DeliveryAttempt::query()->create([
            'communication_id' => $communication->id,
            'communication_provider_connection_id' => $connection->id,
            'provider' => 'resend', 'provider_account_id' => 'acct_recovery', 'status' => 'sending',
            'kind' => 'initial', 'idempotency_key' => 'communication:'.$communication->id,
            'attempt' => 1, 'attempted_at' => now()->subMinutes(2),
        ]);
        Http::fake(['api.resend.com/emails' => Http::response(['id' => 'email_recovered'], 200)]);

        app(CommunicationDeliveryService::class)->deliver($communication);

        $attempts = DeliveryAttempt::query()->where('communication_id', $communication->id)->orderBy('attempt')->get();
        $this->assertCount(2, $attempts);
        $this->assertSame($attempts[0]->idempotency_key, $attempts[1]->idempotency_key);
        $this->assertSame('provider_accepted', $attempts[1]->status);
        Http::assertSentCount(1);
        $this->assertLessThan((int) config('queue.connections.redis.retry_after'), (int) config('horizon.defaults.notifications.timeout'));
        $this->assertGreaterThan((new SendCommunication('', ''))->timeout, (int) config('horizon.defaults.notifications.timeout'));
        putenv('COMMUNICATION_RECOVERY_KEY');
    }

    public function test_delivery_event_authorization_obeys_membership_property_scope(): void
    {
        [$tenant, $propertyA, $actor] = $this->tenantEnvironment(MembershipRole::Operations);
        $propertyB = Property::factory()->create();
        $connection = CommunicationProviderConnection::query()->create([
            'property_id' => $propertyB->id, 'provider' => 'resend', 'account_id' => 'acct_scope',
            'endpoint_key' => 'provider-scope-endpoint-key-0000000001', 'secret_ref' => 'env:SCOPE_KEY',
            'webhook_secret_refs' => ['env:SCOPE_WEBHOOK'], 'from_email' => 'mail@mail.example.com',
            'from_name' => 'Inn', 'allowed_sender_domains' => ['mail.example.com'], 'verified_at' => now(), 'is_enabled' => true,
        ]);
        $event = CommunicationDeliveryEvent::query()->create([
            'property_id' => $propertyB->id,
            'communication_provider_connection_id' => $connection->id,
            'provider' => 'resend', 'provider_account_id' => 'acct_scope', 'provider_event_id' => 'evt_scope',
            'provider_message_id' => 'email_scope', 'type' => 'email.sent', 'occurred_at' => now(),
            'received_at' => now(), 'raw_body_checksum' => hash('sha256', 'scope'),
            'normalized_payload' => [], 'processing_state' => 'pending',
        ]);

        $this->assertSame($tenant->id, $event->tenant_id);
        $this->assertNotSame($propertyA->id, $event->property_id);
        $this->assertFalse($actor->can('view', $event));
    }

    private function metadataCommunication(Property $property, string $recipient, string $suffix): Communication
    {
        return Communication::query()->create([
            'property_id' => $property->id,
            'channel' => 'email',
            'direction' => 'outbound',
            'purpose' => CommunicationPurpose::InternalHost->value,
            'status' => 'queued',
            'subject' => 'Internal '.$suffix,
            'body' => 'Role-minimized update.',
            'metadata' => ['recipient' => $recipient],
        ]);
    }
}
