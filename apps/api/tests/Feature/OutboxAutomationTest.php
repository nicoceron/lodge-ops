<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Jobs\PublishOutboxMessage;
use App\Models\AutomationRule;
use App\Models\Communication;
use App\Models\Deposit;
use App\Models\Guest;
use App\Models\Membership;
use App\Models\OperationalTask;
use App\Models\Outbox;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\InternalStaffNotificationService;
use App\Services\Automation\OutboxBatchPublisher;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class OutboxAutomationTest extends TestCase
{
    use CreatesTenant, DatabaseMigrations;

    public function test_tenant_automation_creates_each_side_effect_once_and_never_sends_mail(): void
    {
        [$tenantA, $propertyA] = $this->tenantEnvironment(authenticate: false);
        $guest = Guest::factory()->create();
        $reservation = Reservation::factory()->create([
            'property_id' => $propertyA->id,
            'primary_guest_id' => $guest->id,
            'confirmation_number' => 'AUTO-100',
        ]);
        $deposit = Deposit::query()->create([
            'reservation_id' => $reservation->id,
            'status' => 'due',
            'currency' => $reservation->currency,
            'amount_minor' => 125000,
            'due_at' => now()->addDay(),
        ]);
        AutomationRule::query()->create([
            'name' => 'Confirmed reservation workflow',
            'trigger' => 'reservation.confirmed',
            'conditions' => ['payload.confirmation_number' => 'AUTO-100'],
            'actions' => [
                ['type' => 'create_task', 'title' => 'Prepare {{reservation.confirmation_number}}'],
                ['type' => 'queue_communication', 'subject' => 'Confirmed {{reservation.confirmation_number}}', 'body' => 'Welcome {{reservation.primary_guest.first_name}}'],
                ['type' => 'deposit_reminder', 'body' => 'Deposit {{deposit.amount_minor}} is due'],
            ],
        ]);
        $message = $this->outbox($reservation, 'reservation.confirmed', [
            'reservation_id' => $reservation->id,
            'confirmation_number' => 'AUTO-100',
        ]);

        [$tenantB] = $this->tenantEnvironment(authenticate: false);
        AutomationRule::query()->create([
            'name' => 'Other tenant workflow',
            'trigger' => 'reservation.confirmed',
            'actions' => [['type' => 'create_task', 'property_id' => $propertyA->id, 'title' => 'Must never run']],
        ]);

        Queue::fake();
        app(TenantContext::class)->clear();
        $this->process($tenantA->id, $message->id);

        $this->assertFalse(app(TenantContext::class)->check());
        $this->assertSame(1, OperationalTask::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->count());
        $this->assertSame(0, OperationalTask::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->count());
        $this->assertSame(2, Communication::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->count());
        $this->assertDatabaseHas('operational_tasks', ['title' => 'Prepare AUTO-100']);
        $this->assertDatabaseHas('communications', [
            'reservation_id' => $reservation->id,
            'status' => 'queued',
            'sent_at' => null,
        ]);
        $this->assertDatabaseHas('communications', [
            'reservation_id' => $reservation->id,
            'status' => 'queued',
            'subject' => 'Deposit reminder',
        ]);

        $published = Outbox::withoutGlobalScopes()->findOrFail($message->id);
        $this->assertNotNull($published->published_at);
        $this->assertSame(1, $published->attempts);
        $this->assertNull($published->last_error);

        (new PublishOutboxMessage($tenantA->id, $message->id, '00000000-0000-4000-8000-000000000099'))
            ->handle(app(AutomationEngine::class), app(TenantContext::class));

        $this->assertSame(1, OperationalTask::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->count());
        $this->assertSame(2, Communication::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->count());
        $this->assertDatabaseHas('communications', ['metadata->deposit_id' => $deposit->id]);
    }

    public function test_internal_notify_delivers_scoped_filament_database_notifications(): void
    {
        [$tenantA, $propertyA, $owner, $ownerMembership] = $this->tenantEnvironment(authenticate: false);
        $propertyB = Property::factory()->create();

        $eligible = User::factory()->create();
        Membership::factory()->create([
            'user_id' => $eligible->id,
            'property_id' => $propertyA->id,
            'role' => MembershipRole::Operations,
            'is_active' => true,
        ]);

        $otherProperty = User::factory()->create();
        Membership::factory()->create([
            'user_id' => $otherProperty->id,
            'property_id' => $propertyB->id,
            'role' => MembershipRole::Operations,
            'is_active' => true,
        ]);

        $inactive = User::factory()->create();
        Membership::factory()->create([
            'user_id' => $inactive->id,
            'property_id' => $propertyA->id,
            'role' => MembershipRole::Operations,
            'is_active' => false,
        ]);

        [$tenantB, , $unrelatedTenantUser] = $this->tenantEnvironment(MembershipRole::Operations, authenticate: false);
        app(TenantContext::class)->set($tenantA, $ownerMembership);
        $reservation = Reservation::factory()->create([
            'property_id' => $propertyA->id,
            'confirmation_number' => 'NOTIFY-100',
        ]);
        $rule = AutomationRule::query()->create([
            'name' => 'Notify operations on confirmation',
            'trigger' => 'reservation.confirmed',
            'actions' => [[
                'type' => 'internal_notify',
                'title' => 'Reservation confirmed',
                'body' => 'Review {{reservation.confirmation_number}}',
                'roles' => ['operations'],
            ]],
        ]);
        $message = $this->outbox($reservation, 'reservation.confirmed', [
            'reservation_id' => $reservation->id,
            'confirmation_number' => 'NOTIFY-100',
        ]);

        Queue::fake();
        app(TenantContext::class)->clear();
        $this->process($tenantA->id, $message->id);

        $this->assertSame(1, DB::table('notifications')->count());
        $notification = DB::table('notifications')->where('notifiable_id', $eligible->id)->first();
        $this->assertNotNull($notification);
        $data = json_decode($notification->data, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('filament', $data['format']);
        $this->assertSame('Reservation confirmed', $data['title']);
        $this->assertSame('Review NOTIFY-100', $data['body']);

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $owner->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $otherProperty->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $inactive->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $unrelatedTenantUser->id]);
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $owner->id)->count());
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $unrelatedTenantUser->id)->count());
        $this->assertSame($tenantB->id, $unrelatedTenantUser->memberships()->withoutGlobalScopes()->first()->tenant_id);

        app(TenantContext::class)->set($tenantA, $ownerMembership);
        $deliveredAgain = app(InternalStaffNotificationService::class)->deliver(
            $message,
            $rule,
            0,
            $rule->actions[0],
            ['reservation' => [
                'id' => $reservation->id,
                'property_id' => $propertyA->id,
                'confirmation_number' => 'NOTIFY-100',
            ]],
        );
        $this->assertSame(0, $deliveredAgain);
        $this->assertSame(1, DB::table('notifications')->count());
    }

    public function test_failures_are_observable_and_retry_without_duplicate_side_effects(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(authenticate: false);
        $reservation = Reservation::factory()->create(['property_id' => $property->id]);
        $rule = AutomationRule::query()->create([
            'name' => 'Initially invalid rule',
            'trigger' => 'reservation.status_changed',
            'actions' => [['type' => 'unsupported']],
        ]);
        $message = $this->outbox($reservation, 'reservation.status_changed', [
            'reservation_id' => $reservation->id,
            'status' => 'cancelled',
        ]);

        Queue::fake();
        app(TenantContext::class)->clear();
        app(OutboxBatchPublisher::class)->publishOne($tenant->id, $message->id);
        $claimed = Outbox::withoutGlobalScopes()->findOrFail($message->id);
        $job = new PublishOutboxMessage($tenant->id, $message->id, $claimed->claim_token);

        try {
            $job->handle(app(AutomationEngine::class), app(TenantContext::class));
            $this->fail('The invalid automation action should fail.');
        } catch (DomainException) {
            // The outbox owns retry state; the queue may safely retry this job.
        }

        $failed = Outbox::withoutGlobalScopes()->findOrFail($message->id);
        $this->assertSame(1, $failed->attempts);
        $this->assertNull($failed->published_at);
        $this->assertStringContainsString('Unsupported automation action', $failed->last_error);
        $this->assertFalse(app(TenantContext::class)->check());

        app(TenantContext::class)->set($tenant);
        $rule->forceFill([
            'actions' => [
                ['type' => 'create_task', 'title' => 'Cancellation review'],
                ['type' => 'queue_communication', 'body' => 'Reservation cancelled'],
            ],
        ])->save();
        app(TenantContext::class)->clear();

        $job->handle(app(AutomationEngine::class), app(TenantContext::class));

        $retried = Outbox::withoutGlobalScopes()->findOrFail($message->id);
        $this->assertSame(2, $retried->attempts);
        $this->assertNotNull($retried->published_at);
        $this->assertNull($retried->last_error);
        $this->assertSame(1, OperationalTask::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, Communication::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_payment_succeeded_rules_are_dispatched_through_the_same_tenant_safe_handler(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(authenticate: false);
        $reservation = Reservation::factory()->create(['property_id' => $property->id]);
        AutomationRule::query()->create([
            'name' => 'Payment receipt',
            'trigger' => 'payment.succeeded',
            'conditions' => ['payload.amount_minor' => 5000],
            'actions' => [['type' => 'queue_communication', 'subject' => 'Payment received', 'body' => 'Thank you']],
        ]);
        $message = $this->outbox($reservation, 'payment.succeeded', [
            'reservation_id' => $reservation->id,
            'payment_id' => '00000000-0000-4000-8000-000000000001',
            'amount_minor' => 5000,
        ]);

        Queue::fake();
        app(TenantContext::class)->clear();
        $this->process($tenant->id, $message->id);

        $this->assertDatabaseHas('communications', [
            'tenant_id' => $tenant->id,
            'reservation_id' => $reservation->id,
            'subject' => 'Payment received',
            'status' => 'queued',
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function outbox(Reservation $reservation, string $eventType, array $payload): Outbox
    {
        return Outbox::query()->create([
            'aggregate_type' => 'reservation',
            'aggregate_id' => $reservation->id,
            'event_type' => $eventType,
            'payload' => $payload,
            'occurred_at' => now(),
            'available_at' => now(),
        ]);
    }

    private function process(string $tenantId, string $messageId): void
    {
        app(OutboxBatchPublisher::class)->publishOne($tenantId, $messageId);
        $claimed = Outbox::withoutGlobalScopes()->findOrFail($messageId);

        (new PublishOutboxMessage($tenantId, $messageId, $claimed->claim_token))
            ->handle(app(AutomationEngine::class), app(TenantContext::class));
    }
}
