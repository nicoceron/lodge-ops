<?php

namespace Database\Seeders;

use App\Enums\DocumentKind;
use App\Enums\ReservationStatus;
use App\Models\DocumentTemplate;
use App\Models\Guest;
use App\Models\GuestPortalAccessToken;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class ClientClosedLoopUatSeeder extends Seeder
{
    public const CROSS_PROPERTY_RESERVATION_ID = '22222222-2222-4222-8222-222222222222';

    public const EXPIRED_GUEST_TOKEN = 'g_expired_client_uat_link_00000001';

    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'demo-lodge')->firstOrFail();
        $context = app(TenantContext::class);
        $context->set($tenant);

        try {
            foreach (DocumentKind::cases() as $kind) {
                DocumentTemplate::query()->firstOrCreate(
                    ['kind' => $kind->value, 'version' => 1],
                    [
                        'name' => str($kind->value)->replace('_', ' ')->title(),
                        'definition' => ['locale' => null],
                        'is_active' => true,
                    ],
                );
            }

            $demoReservation = Reservation::query()
                ->where('confirmation_number', 'RSV-DEMO-001')
                ->firstOrFail();

            GuestPortalAccessToken::query()->updateOrCreate(
                ['token_hash' => hash('sha256', DatabaseSeeder::DEMO_GUEST_PORTAL_TOKEN)],
                [
                    'reservation_id' => $demoReservation->id,
                    'guest_id' => $demoReservation->primary_guest_id,
                    'session_hash' => null,
                    'expires_at' => now()->addWeek(),
                    'exchanged_at' => null,
                    'session_expires_at' => null,
                    'last_used_at' => null,
                    'revoked_at' => null,
                ],
            );

            $property = Property::query()->firstOrCreate(
                ['code' => 'UAT-ISOLATION'],
                ['name' => 'UAT isolation property', 'timezone' => 'UTC', 'is_active' => true],
            );
            $guest = Guest::query()->firstOrCreate(
                ['email' => 'uat-isolation@example.test'],
                ['first_name' => 'UAT', 'last_name' => 'Isolation'],
            );
            $startsAt = CarbonImmutable::now('UTC')->addMonth()->startOfDay()->addHours(15);

            $reservation = Reservation::query()->firstOrCreate(
                ['id' => self::CROSS_PROPERTY_RESERVATION_ID],
                [
                    'confirmation_number' => 'RSV-UAT-ISOLATION',
                    'property_id' => $property->id,
                    'primary_guest_id' => $guest->id,
                    'status' => ReservationStatus::Confirmed,
                    'starts_at' => $startsAt,
                    'ends_at' => $startsAt->addDay()->subHours(4),
                    'adults' => 1,
                    'currency' => 'USD',
                    'subtotal_minor' => 10_000,
                    'tax_minor' => 0,
                    'total_minor' => 10_000,
                    'confirmed_at' => now(),
                ],
            );
            GuestPortalAccessToken::query()->updateOrCreate(
                ['token_hash' => hash('sha256', self::EXPIRED_GUEST_TOKEN)],
                [
                    'reservation_id' => $reservation->id,
                    'guest_id' => $guest->id,
                    'session_hash' => null,
                    'expires_at' => now()->subMinute(),
                    'exchanged_at' => null,
                    'session_expires_at' => null,
                    'last_used_at' => null,
                    'revoked_at' => null,
                ],
            );
        } finally {
            $context->clear();
        }
    }
}
