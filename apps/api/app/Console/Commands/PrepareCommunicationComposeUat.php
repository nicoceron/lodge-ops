<?php

namespace App\Console\Commands;

use App\Enums\CommunicationPurpose;
use App\Enums\ReservationStatus;
use App\Models\Communication;
use App\Models\CommunicationProviderConnection;
use App\Models\DeliveryAttempt;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Services\Communications\CommunicationPreferenceService;
use App\Services\GuestPortalTokenService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class PrepareCommunicationComposeUat extends Command
{
    protected $signature = 'uat:prepare-communication-journey {run} {--provider} {--await-status=}';

    protected $description = 'Prepare explicitly test-origin scheduling or signed-provider fixtures for the P3-04 Compose journey.';

    public function handle(
        TenantContext $context,
        GuestPortalTokenService $tokens,
        CommunicationPreferenceService $preferences,
    ): int {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Communication UAT fixtures may only be prepared in local or testing environments.');

            return self::FAILURE;
        }
        $run = (string) $this->argument('run');
        if (! preg_match('/^[A-Za-z0-9-]{6,40}$/', $run)) {
            $this->error('The UAT run identity is invalid.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->where('slug', 'demo-lodge')->firstOrFail();
        $context->clear();
        try {
            $context->set($tenant);
            $property = Property::query()->where('name', 'Estancia Viento Sur')->firstOrFail();

            if ($this->option('provider') && filled($this->option('await-status'))) {
                return $this->awaitProviderFixtureStatus($run, (string) $this->option('await-status'));
            }

            return $this->option('provider')
                ? $this->prepareProviderFixture($property, $run)
                : $this->prepareSchedulingFixture($property, $run, $tokens, $preferences);
        } finally {
            $context->clear();
        }
    }

    private function prepareSchedulingFixture(
        Property $property,
        string $run,
        GuestPortalTokenService $tokens,
        CommunicationPreferenceService $preferences,
    ): int {
        $existingConnection = CommunicationProviderConnection::query()
            ->where('property_id', $property->id)->where('provider', 'resend')->first();
        if ($existingConnection !== null) {
            if (! str_starts_with($existingConnection->account_id, 'test-origin-compose-uat')) {
                $this->error('Refusing to replace a non-UAT communication provider connection.');

                return self::FAILURE;
            }
            $existingConnection->delete();
        }

        $guest = Guest::query()->updateOrCreate(
            ['email' => "p3-04-{$run}@example.test"],
            ['first_name' => 'P3-04', 'last_name' => 'Test Origin'],
        );
        $now = now()->toImmutable()->utc();
        $arrival = Reservation::query()->updateOrCreate(
            ['confirmation_number' => "P304-A-{$run}"],
            [
                'property_id' => $property->id,
                'primary_guest_id' => $guest->id,
                'status' => ReservationStatus::Confirmed,
                'source' => 'p3-04-test-origin',
                'starts_at' => $now->addDays(7)->subMinutes(5),
                'ends_at' => $now->addDays(10),
                'adults' => 2,
                'children' => 0,
                'currency' => 'USD',
                'subtotal_minor' => 10_000,
                'tax_minor' => 0,
                'total_minor' => 10_000,
                'confirmed_at' => $now->subDay(),
            ],
        );
        $survey = Reservation::query()->updateOrCreate(
            ['confirmation_number' => "P304-S-{$run}"],
            [
                'property_id' => $property->id,
                'primary_guest_id' => $guest->id,
                'status' => ReservationStatus::CheckedOut,
                'source' => 'p3-04-test-origin',
                'starts_at' => $now->subDays(3),
                'ends_at' => $now->subMinutes(10),
                'actual_start_at' => $now->subDays(3),
                'actual_end_at' => $now->subMinutes(5),
                'adults' => 2,
                'children' => 0,
                'currency' => 'USD',
                'subtotal_minor' => 10_000,
                'tax_minor' => 0,
                'total_minor' => 10_000,
                'confirmed_at' => $now->subDays(10),
            ],
        );
        $preferences->record(
            $guest,
            CommunicationPurpose::Survey,
            true,
            'p3_04_test_origin_setup',
            $property,
        );
        $issued = $tokens->issue($survey, $guest);

        $this->line(json_encode([
            'origin' => 'deterministic_test_fixture',
            'arrival_reservation_id' => $arrival->id,
            'survey_reservation_id' => $survey->id,
            'guest_email' => $guest->email,
            'guest_token' => $issued['token'],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function prepareProviderFixture(Property $property, string $run): int
    {
        $guest = Guest::query()->where('email', "p3-04-{$run}@example.test")->firstOrFail();
        $existingConnection = CommunicationProviderConnection::query()
            ->where('property_id', $property->id)->where('provider', 'resend')->first();
        if ($existingConnection !== null) {
            if (! str_starts_with($existingConnection->account_id, 'test-origin-compose-uat')) {
                $this->error('Refusing to replace a non-UAT communication provider connection.');

                return self::FAILURE;
            }
            $existingConnection->delete();
        }

        $endpointKey = 'p3-04-test-origin-'.hash('sha256', $run);
        $providerMessageId = 'email_test_origin_'.$run;
        $connection = CommunicationProviderConnection::query()->create([
            'property_id' => $property->id,
            'provider' => 'resend',
            'account_id' => 'test-origin-compose-uat-'.$run,
            'endpoint_key' => $endpointKey,
            'secret_ref' => 'env:COMMUNICATION_UAT_WEBHOOK_SECRET',
            'webhook_secret_refs' => ['env:COMMUNICATION_UAT_WEBHOOK_SECRET'],
            'from_email' => 'uat@mail.example.test',
            'from_name' => 'Inn test origin',
            'allowed_sender_domains' => ['mail.example.test'],
            'verified_at' => now(),
            'verification_checked_at' => now(),
            'verification_reference' => 'deterministic-test-origin',
            'is_enabled' => true,
        ]);
        $startedAt = now()->toImmutable();
        $communication = Communication::query()->create([
            'property_id' => $property->id,
            'guest_id' => $guest->id,
            'channel' => 'email',
            'direction' => 'outbound',
            'purpose' => CommunicationPurpose::Test->value,
            'status' => 'provider_accepted',
            'subject' => "[TEST-ORIGIN {$run}] provider event",
            'body' => 'Deterministic signed fixture; not evidence of provider-origin delivery.',
            'accepted_at' => $startedAt,
            'delivery_idempotency_started_at' => $startedAt,
            'delivery_idempotency_expires_at' => $startedAt->addHours(24),
            'automation_key' => 'test-origin-provider:'.$run,
            'metadata' => [
                'recipient' => $guest->email,
                'is_test' => true,
                'origin' => 'deterministic_test_fixture',
            ],
        ]);
        DeliveryAttempt::query()->create([
            'communication_id' => $communication->id,
            'communication_provider_connection_id' => $connection->id,
            'provider' => 'resend',
            'provider_account_id' => $connection->account_id,
            'provider_reference' => $providerMessageId,
            'provider_message_id' => $providerMessageId,
            'status' => 'provider_accepted',
            'kind' => 'initial',
            'idempotency_key' => 'communication:'.$communication->id,
            'attempt' => 1,
            'retry_state' => 'none',
            'attempted_at' => $startedAt,
            'accepted_at' => $startedAt,
        ]);

        $this->line(json_encode([
            'origin' => 'deterministic_test_fixture',
            'endpoint_key' => $endpointKey,
            'provider_message_id' => $providerMessageId,
            'communication_id' => $communication->id,
            'subject' => $communication->subject,
            'recipient' => $guest->email,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function awaitProviderFixtureStatus(string $run, string $expectedStatus): int
    {
        if (! in_array($expectedStatus, ['delivered', 'hard_bounced', 'complained', 'suppressed'], true)) {
            $this->error('The requested UAT communication status is invalid.');

            return self::FAILURE;
        }

        $subject = "[TEST-ORIGIN {$run}] provider event";
        $deadline = microtime(true) + 30;
        do {
            $communication = Communication::query()
                ->where('subject', $subject)
                ->where('status', $expectedStatus)
                ->latest()
                ->first();
            if ($communication !== null) {
                $this->line(json_encode([
                    'origin' => 'deterministic_test_fixture',
                    'communication_id' => $communication->id,
                    'status' => $communication->status,
                ], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);

        $this->error("Timed out waiting for the deterministic test-origin fixture to reach {$expectedStatus}.");

        return self::FAILURE;
    }
}
