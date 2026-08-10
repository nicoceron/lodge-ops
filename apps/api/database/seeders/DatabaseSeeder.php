<?php

namespace Database\Seeders;

use App\Enums\AllocationStatus;
use App\Enums\MembershipRole;
use App\Enums\ReservationStatus;
use App\Enums\ResourceType;
use App\Enums\TaskStatus;
use App\Models\AutomationRule;
use App\Models\Guest;
use App\Models\GuestPortalAccessToken;
use App\Models\GuestPortalDocument;
use App\Models\Membership;
use App\Models\MessageTemplate;
use App\Models\OperationalTask;
use App\Models\Program;
use App\Models\ProgramResourceRequirement;
use App\Models\ProgramTaskTemplate;
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

            $staff = $this->seedTeam($tenant->id, $property->id);
            $programs = $this->seedPrograms($property->id);
            $this->seedResources($property->id, $staff);
            $this->seedAutomation();

            $guest = Guest::query()->firstOrCreate(
                ['email' => 'guest@example.com'],
                ['first_name' => 'Sofia', 'last_name' => 'Martinez', 'phone' => '+5492966123456', 'language' => 'es'],
            );
            $room = Resource::query()->where('code', '101')->firstOrFail();
            $reservation = Reservation::query()->updateOrCreate(
                ['confirmation_number' => 'RSV-DEMO-001'],
                [
                    'property_id' => $property->id,
                    'program_id' => $programs['stay']->id,
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

    /** @return array<string, User> */
    private function seedTeam(string $tenantId, string $propertyId): array
    {
        $team = [
            'guide' => ['name' => 'Mateo Rios', 'email' => 'guide@example.com', 'role' => MembershipRole::Guide],
            'kitchen' => ['name' => 'Lucia Cocina', 'email' => 'kitchen@example.com', 'role' => MembershipRole::Kitchen],
            'operations' => ['name' => 'Ana Operaciones', 'email' => 'operations@example.com', 'role' => MembershipRole::Operations],
            'finance' => ['name' => 'Diego Finanzas', 'email' => 'finance@example.com', 'role' => MembershipRole::Finance],
        ];

        return collect($team)->map(function (array $profile) use ($tenantId, $propertyId): User {
            $user = User::query()->updateOrCreate(
                ['email' => $profile['email']],
                ['name' => $profile['name'], 'password' => 'password', 'email_verified_at' => now()],
            );
            Membership::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenantId, 'user_id' => $user->id],
                ['property_id' => $propertyId, 'role' => $profile['role'], 'is_active' => true],
            );

            return $user;
        })->all();
    }

    /** @return array<string, Program> */
    private function seedPrograms(string $propertyId): array
    {
        $definitions = [
            'stay' => ['name' => 'Lodge stay', 'color' => '#4F6F52', 'accommodation' => true, 'duration' => 1440, 'capacity' => 20, 'price' => 300000],
            'stag' => ['name' => 'Red Stag Hunting', 'color' => '#8C4438', 'accommodation' => true, 'duration' => 4320, 'capacity' => 6, 'price' => 1250000],
            'double' => ['name' => 'The Patagonian Double', 'color' => '#315E64', 'accommodation' => true, 'duration' => 5760, 'capacity' => 8, 'price' => 1840000],
            'fishing' => ['name' => 'Fly fishing', 'color' => '#2563EB', 'accommodation' => false, 'duration' => 480, 'capacity' => 4, 'price' => 185000],
            'horseback' => ['name' => 'Horseback ride', 'color' => '#B56A2C', 'accommodation' => false, 'duration' => 180, 'capacity' => 8, 'price' => 95000],
            'trekking' => ['name' => 'Patagonian trekking', 'color' => '#6B7280', 'accommodation' => false, 'duration' => 360, 'capacity' => 10, 'price' => 120000],
        ];

        $programs = collect($definitions)->map(function (array $definition) use ($propertyId): Program {
            return Program::query()->updateOrCreate(
                ['property_id' => $propertyId, 'name' => $definition['name']],
                [
                    'description' => "Operational demo program for {$definition['name']}.",
                    'display_color' => $definition['color'],
                    'requires_accommodation' => $definition['accommodation'],
                    'default_duration_minutes' => $definition['duration'],
                    'capacity' => $definition['capacity'],
                    'price_minor' => $definition['price'],
                    'currency' => 'USD',
                    'is_active' => true,
                ],
            );
        })->all();

        foreach ([
            ['program' => 'stag', 'type' => ResourceType::Guide, 'minimum' => 1, 'ratio' => 1, 'capabilities' => ['hunting'], 'languages' => ['en']],
            ['program' => 'double', 'type' => ResourceType::Guide, 'minimum' => 1, 'ratio' => 2, 'capabilities' => ['fishing'], 'languages' => ['en']],
            ['program' => 'fishing', 'type' => ResourceType::Guide, 'minimum' => 1, 'ratio' => 2, 'capabilities' => ['fishing'], 'languages' => []],
            ['program' => 'horseback', 'type' => ResourceType::Horse, 'minimum' => 1, 'ratio' => 1, 'capabilities' => [], 'languages' => []],
            ['program' => 'trekking', 'type' => ResourceType::Guide, 'minimum' => 1, 'ratio' => 6, 'capabilities' => ['trekking'], 'languages' => []],
        ] as $index => $requirement) {
            ProgramResourceRequirement::query()->updateOrCreate(
                ['program_id' => $programs[$requirement['program']]->id, 'resource_type' => $requirement['type'], 'sort_order' => $index],
                [
                    'minimum_quantity' => $requirement['minimum'],
                    'guests_per_resource' => $requirement['ratio'],
                    'capabilities' => $requirement['capabilities'],
                    'languages' => $requirement['languages'],
                ],
            );
        }

        foreach (['stag', 'double', 'stay'] as $programKey) {
            foreach ([
                ['title' => 'Confirm guide and resource plan', 'role' => MembershipRole::Operations, 'offset' => -10080, 'priority' => 'high'],
                ['title' => 'Confirm menu and dietary requirements', 'role' => MembershipRole::Kitchen, 'offset' => -4320, 'priority' => 'normal'],
                ['title' => 'Confirm arrival transfer', 'role' => MembershipRole::Operations, 'offset' => -1440, 'priority' => 'high'],
            ] as $index => $template) {
                ProgramTaskTemplate::query()->updateOrCreate(
                    ['program_id' => $programs[$programKey]->id, 'title' => $template['title']],
                    [
                        'assignee_role' => $template['role'],
                        'priority' => $template['priority'],
                        'due_offset_minutes' => $template['offset'],
                        'sort_order' => $index,
                        'is_active' => true,
                    ],
                );
            }
        }

        return $programs;
    }

    /** @param array<string, User> $staff */
    private function seedResources(string $propertyId, array $staff): void
    {
        $resources = [
            ['code' => '101', 'name' => 'Coihue Suite', 'type' => ResourceType::Room, 'capacity' => 2],
            ['code' => '102', 'name' => 'Lenga Suite', 'type' => ResourceType::Room, 'capacity' => 2],
            ['code' => 'CABIN', 'name' => 'River Cabin', 'type' => ResourceType::Room, 'capacity' => 4],
            ['code' => 'GUIDE-MATEO', 'name' => 'Mateo Rios', 'type' => ResourceType::Guide, 'capacity' => 2, 'user_id' => $staff['guide']->id, 'attributes' => ['capabilities' => ['hunting', 'fishing'], 'languages' => ['es', 'en']]],
            ['code' => 'HORSES', 'name' => 'Horse pool', 'type' => ResourceType::Horse, 'capacity' => 8, 'attributes' => ['capabilities' => ['trail', 'hunting']]],
            ['code' => 'BOAT-01', 'name' => 'Drift boat', 'type' => ResourceType::Boat, 'capacity' => 3],
            ['code' => 'TRANSFER-01', 'name' => 'Transfer 4x4', 'type' => ResourceType::Vehicle, 'capacity' => 6],
            ['code' => 'BUYOUT', 'name' => 'Full lodge buyout', 'type' => ResourceType::Venue, 'capacity' => 1, 'is_buyout' => true],
        ];

        foreach ($resources as $resource) {
            Resource::query()->updateOrCreate(
                ['code' => $resource['code']],
                [
                    'property_id' => $propertyId,
                    'name' => $resource['name'],
                    'type' => $resource['type'],
                    'capacity' => $resource['capacity'],
                    'user_id' => $resource['user_id'] ?? null,
                    'attributes' => $resource['attributes'] ?? null,
                    'is_buyout' => $resource['is_buyout'] ?? false,
                    'is_active' => true,
                ],
            );
        }
    }

    private function seedAutomation(): void
    {
        $rules = [
            ['name' => 'Reservation confirmation and private portal', 'trigger' => 'reservation.confirmed', 'actions' => [[
                'type' => 'guest_portal_invitation',
                'purpose' => 'pre_arrival',
                'subject' => 'Your reservation {{reservation.confirmation_number}} is confirmed',
                'body' => 'Your reservation is confirmed. Complete your travel details, documents, and payment evidence here: {{guest_portal.url}}',
            ]]],
            ['name' => 'Arrival preparation reminder', 'trigger' => 'reservation.arrival_approaching', 'actions' => [[
                'type' => 'queue_communication',
                'subject' => 'Your lodge arrival is approaching',
                'body' => 'Your arrival is {{payload.days_before}} day(s) away. Please review your travel details and contact us with any changes.',
            ]]],
            ['name' => 'Overdue deposit reminder', 'trigger' => 'deposit.overdue', 'actions' => [[
                'type' => 'deposit_reminder',
                'subject' => 'Reservation payment reminder',
                'body' => 'A payment of {{deposit.amount_minor}} {{deposit.currency}} is overdue. Please reply with your bank transfer confirmation.',
            ]]],
            ['name' => 'Post-stay survey invitation', 'trigger' => 'reservation.checkout_completed', 'actions' => [[
                'type' => 'guest_portal_invitation',
                'purpose' => 'survey',
                'subject' => 'How was your stay?',
                'body' => 'Thank you for staying with us. Share your experience through your private reservation center: {{guest_portal.url}}',
            ]]],
        ];

        foreach ($rules as $rule) {
            AutomationRule::query()->updateOrCreate(
                ['name' => $rule['name'], 'trigger' => $rule['trigger']],
                ['conditions' => null, 'actions' => $rule['actions'], 'is_active' => true],
            );
        }

        foreach ([
            'confirmation' => ['Reservation confirmation', 'Your reservation is confirmed.'],
            'payment' => ['Payment instructions', 'Please complete your bank transfer and upload the confirmation.'],
            'pre_arrival' => ['Pre-arrival recommendations', 'Review travel, clothing, and activity recommendations before departure.'],
            'arrival' => ['Arrival instructions', 'Your transfer and check-in details are ready.'],
            'survey' => ['Thank you and survey', 'Thank you for visiting. We would value your feedback.'],
        ] as $key => [$name, $body]) {
            $template = MessageTemplate::query()->updateOrCreate(
                ['key' => $key, 'channel' => 'email'],
                ['name' => $name, 'is_active' => true],
            );
            $template->versions()->updateOrCreate(
                ['version' => 1],
                ['language' => 'en', 'subject' => $name, 'body' => $body, 'published_at' => now()],
            );
        }
    }
}
