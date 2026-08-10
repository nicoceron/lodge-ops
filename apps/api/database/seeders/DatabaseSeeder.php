<?php

namespace Database\Seeders;

use App\Enums\AllocationStatus;
use App\Enums\MembershipRole;
use App\Enums\ReservationStatus;
use App\Enums\ResourceType;
use App\Enums\TaskStatus;
use App\Models\Guest;
use App\Models\GuestPortalAccessToken;
use App\Models\GuestPortalDocument;
use App\Models\Membership;
use App\Models\OperationalTask;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public const DEMO_GUEST_PORTAL_TOKEN = 'g_7JvK2pQ9xR4mN8tW3cD6hF1sB5yE0uA';

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $user = User::query()->updateOrCreate(
                ['email' => 'admin@example.com'],
                ['name' => 'LodgeOps Owner', 'password' => 'password', 'email_verified_at' => now()],
            );
            $tenant = Tenant::query()->where('slug', 'demo-lodge')->first()
                ?? Tenant::query()->forceCreate([
                    'id' => '11111111-1111-4111-8111-111111111111',
                    'slug' => 'demo-lodge',
                    'name' => 'Estancia Viento Sur',
                    'timezone' => 'America/Argentina/Rio_Gallegos',
                    'currency' => 'USD',
                    'locale' => 'es',
                    'is_active' => true,
                ]);

            $context = app(TenantContext::class);
            $context->set($tenant);

            $property = Property::query()->firstOrCreate(
                ['code' => 'MAIN'],
                ['name' => 'Estancia Viento Sur', 'timezone' => 'America/Argentina/Rio_Gallegos', 'is_active' => true],
            );
            $membership = Membership::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['property_id' => $property->id, 'role' => MembershipRole::Owner, 'is_active' => true],
            );
            $context->set($tenant, $membership);

            $guest = Guest::query()->firstOrCreate(
                ['email' => 'guest@example.com'],
                ['first_name' => 'Sofia', 'last_name' => 'Martinez', 'phone' => '+5492966123456', 'language' => 'es'],
            );
            $room = Resource::query()->firstOrCreate(
                ['code' => '101'],
                ['property_id' => $property->id, 'name' => 'Coihue Suite', 'type' => ResourceType::Room, 'capacity' => 2, 'is_active' => true],
            );
            $reservation = Reservation::query()->firstOrCreate(
                ['confirmation_number' => 'RSV-DEMO-001'],
                [
                    'property_id' => $property->id,
                    'primary_guest_id' => $guest->id,
                    'status' => ReservationStatus::Confirmed,
                    'starts_at' => now()->addDay()->setTime(15, 0),
                    'ends_at' => now()->addDays(3)->setTime(11, 0),
                    'adults' => 2,
                    'currency' => 'USD',
                    'subtotal_minor' => 600000,
                    'tax_minor' => 114000,
                    'total_minor' => 714000,
                    'confirmed_at' => now(),
                ],
            );
            $reservation->allocations()->firstOrCreate(
                ['resource_id' => $room->id],
                ['status' => AllocationStatus::Confirmed, 'starts_at' => $reservation->starts_at, 'ends_at' => $reservation->ends_at, 'quantity' => 1],
            );
            OperationalTask::query()->firstOrCreate(
                ['reservation_id' => $reservation->id, 'title' => 'Preparar habitacion'],
                ['property_id' => $property->id, 'status' => TaskStatus::Todo, 'priority' => 'high', 'due_at' => $reservation->starts_at->subHours(2)],
            );
            GuestPortalDocument::query()->firstOrCreate(
                ['property_id' => $property->id, 'slug' => 'outdoor-waiver', 'version' => '1.0'],
                [
                    'title' => 'Outdoor activity waiver',
                    'body' => 'I understand the inherent risks of remote outdoor activities and agree to follow the instructions of the lodge team.',
                    'is_active' => true,
                ],
            );
            GuestPortalAccessToken::query()->updateOrCreate(
                ['token_hash' => hash('sha256', self::DEMO_GUEST_PORTAL_TOKEN)],
                [
                    'reservation_id' => $reservation->id,
                    'guest_id' => $guest->id,
                    'session_hash' => null,
                    'expires_at' => now()->addWeek(),
                    'exchanged_at' => null,
                    'session_expires_at' => null,
                    'last_used_at' => null,
                    'revoked_at' => null,
                ],
            );

            $context->clear();
        });
    }
}
