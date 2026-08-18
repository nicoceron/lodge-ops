<?php

namespace Database\Seeders;

use App\Enums\AllocationStatus;
use App\Enums\DepositStatus;
use App\Enums\FolioLineType;
use App\Enums\HousekeepingStatus;
use App\Enums\MembershipRole;
use App\Enums\PaymentStatus;
use App\Enums\ProposalStatus;
use App\Enums\ReservationStatus;
use App\Enums\ResourceKind;
use App\Enums\TaskStatus;
use App\Models\Allocation;
use App\Models\AutomationRule;
use App\Models\CalendarFeed;
use App\Models\CancellationPolicy;
use App\Models\CancellationPolicyTier;
use App\Models\CommissionAccrual;
use App\Models\CostRecord;
use App\Models\CrmActivity;
use App\Models\Deposit;
use App\Models\DepositPolicy;
use App\Models\ExchangeRate;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\GuestPortalAccessToken;
use App\Models\GuestPortalDocument;
use App\Models\GuestPortalProfile;
use App\Models\Membership;
use App\Models\MessageTemplate;
use App\Models\OperationalTask;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Program;
use App\Models\ProgramResourceRequirement;
use App\Models\ProgramTaskTemplate;
use App\Models\Property;
use App\Models\Proposal;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Reservation;
use App\Models\ReservationNote;
use App\Models\Resource;
use App\Models\ResourceCategory;
use App\Models\ServiceOccurrence;
use App\Models\Survey;
use App\Models\TaxRule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CalendarFeedService;
use App\Services\FolioService;
use App\Services\ResourceCatalog;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class DatabaseSeeder extends Seeder
{
    public const DEMO_GUEST_PORTAL_TOKEN = 'g_7JvK2pQ9xR4mN8tW3cD6hF1sB5yE0uA';

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->environment('production') && ! filter_var(env('ALLOW_DEMO_SEEDING', false), FILTER_VALIDATE_BOOL)) {
            throw new LogicException('Demo data seeding is disabled in production. Set ALLOW_DEMO_SEEDING=true only for an intentional demo environment.');
        }

        DB::transaction(function (): void {
            $user = User::query()->updateOrCreate(
                ['email' => 'admin@example.com'],
                ['name' => 'Inn Owner', 'password' => 'password', 'email_verified_at' => now()],
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
            $today = CarbonImmutable::now($tenant->timezone)->startOfDay()->utc();

            $property = Property::query()->firstOrCreate(
                ['code' => 'MAIN'],
                ['name' => 'Estancia Viento Sur', 'timezone' => 'America/Argentina/Rio_Gallegos', 'is_active' => true],
            );
            $membership = Membership::query()->updateOrCreate(
                ['user_id' => $user->id],
                ['property_id' => $property->id, 'role' => MembershipRole::Administrator, 'is_active' => true],
            );
            $context->set($tenant, $membership);

            $staff = $this->seedTeam($tenant->id, $property->id);
            $catalog = app(ResourceCatalog::class)->ensure($property, $this->demoResourceCategories());
            $programs = $this->seedPrograms($property->id, $catalog);
            $resources = $this->seedResources($property->id, $staff, $catalog);
            $this->seedBookingPoliciesAndRates($property, $catalog);
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
                    'starts_at' => $today->addDay()->addHours(15),
                    'ends_at' => $today->addDays(3)->addHours(11),
                    'adults' => 2,
                    'currency' => 'USD',
                    'subtotal_minor' => 600000,
                    'tax_minor' => 114000,
                    'total_minor' => 714000,
                    'confirmed_at' => now(),
                ],
            );
            $requestedStay = $reservation->allocations()->whereNull('service_occurrence_id')->oldest()->first()
                ?? new Allocation(['reservation_id' => $reservation->id]);
            $requestedStay->fill([
                'requested_category_id' => $catalog['room']->id,
                'resource_id' => null,
                'service_occurrence_id' => null,
                'status' => AllocationStatus::Confirmed,
                'starts_at' => $reservation->starts_at,
                'ends_at' => $reservation->ends_at,
                'quantity' => 1,
            ])->save();
            ReservationNote::query()->firstOrCreate(
                ['reservation_id' => $reservation->id, 'body' => 'Guest prefers a quiet cabin away from the service path.'],
                ['kind' => 'guest_request', 'created_by' => $staff['operations']->id, 'occurred_at' => $today->subDays(2)],
            );
            if (! CalendarFeed::query()->where('resource_id', $room->id)->where('name', 'Demo channel · Coihue Suite')->exists()) {
                app(CalendarFeedService::class)->create($property->id, $room->id, 'Demo channel · Coihue Suite');
            }
            OperationalTask::query()->firstOrCreate(
                ['reservation_id' => $reservation->id, 'title' => 'Preparar habitacion'],
                ['property_id' => $property->id, 'status' => TaskStatus::Todo, 'priority' => 'high', 'due_at' => $reservation->starts_at->subHours(2)],
            );
            $this->seedDemoOperations($property, $programs, $resources, $staff, $today);
            $this->seedDemoTrends($property, $programs, $resources, $staff, $today);
            $this->seedDemoSalesAndGuestExperience($property, $programs, $staff, $reservation, $guest, $today);
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

    /**
     * Patagonia is a complete demo catalog, not a platform-level type system.
     *
     * @return list<array{kind: ResourceKind, slug: string, name: string, counts_as_stay: bool, default_capacity: int, sort_order: int}>
     */
    private function demoResourceCategories(): array
    {
        return [
            ['kind' => ResourceKind::Place, 'slug' => 'room', 'name' => 'Cabin', 'counts_as_stay' => true, 'default_capacity' => 2, 'sort_order' => 10],
            ['kind' => ResourceKind::Place, 'slug' => 'venue', 'name' => 'Full property', 'counts_as_stay' => true, 'default_capacity' => 1, 'sort_order' => 20],
            ['kind' => ResourceKind::Asset, 'slug' => 'horse', 'name' => 'Horse', 'counts_as_stay' => false, 'default_capacity' => 1, 'sort_order' => 30],
            ['kind' => ResourceKind::Asset, 'slug' => 'boat', 'name' => 'Boat', 'counts_as_stay' => false, 'default_capacity' => 3, 'sort_order' => 40],
            ['kind' => ResourceKind::Asset, 'slug' => 'vehicle', 'name' => 'Vehicle', 'counts_as_stay' => false, 'default_capacity' => 4, 'sort_order' => 50],
            ['kind' => ResourceKind::Asset, 'slug' => 'equipment', 'name' => 'Equipment', 'counts_as_stay' => false, 'default_capacity' => 1, 'sort_order' => 60],
            ['kind' => ResourceKind::Crew, 'slug' => 'guide', 'name' => 'Guide', 'counts_as_stay' => false, 'default_capacity' => 1, 'sort_order' => 70],
            ['kind' => ResourceKind::Crew, 'slug' => 'staff', 'name' => 'Staff', 'counts_as_stay' => false, 'default_capacity' => 1, 'sort_order' => 80],
        ];
    }

    /** @return array<string, User> */
    private function seedTeam(string $tenantId, string $propertyId): array
    {
        $team = [
            'owner' => ['name' => 'Sofia Propietaria', 'email' => 'owner@example.com', 'role' => MembershipRole::Owner],
            'manager' => ['name' => 'Valentina Gerencia', 'email' => 'manager@example.com', 'role' => MembershipRole::Manager],
            'sales' => ['name' => 'Tomas Ventas', 'email' => 'sales@example.com', 'role' => MembershipRole::Sales],
            'guide' => ['name' => 'Mateo Rios', 'email' => 'guide@example.com', 'role' => MembershipRole::Guide],
            'kitchen' => ['name' => 'Lucia Cocina', 'email' => 'kitchen@example.com', 'role' => MembershipRole::Kitchen],
            'housekeeping' => ['name' => 'Elena Habitaciones', 'email' => 'housekeeping@example.com', 'role' => MembershipRole::Housekeeping],
            'operations' => ['name' => 'Ana Operaciones', 'email' => 'operations@example.com', 'role' => MembershipRole::Operations],
            'finance' => ['name' => 'Diego Finanzas', 'email' => 'finance@example.com', 'role' => MembershipRole::Finance],
            'viewer' => ['name' => 'Auditoria Demo', 'email' => 'viewer@example.com', 'role' => MembershipRole::Viewer],
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

    /**
     * @param  Collection<string, ResourceCategory>  $catalog
     * @return array<string, Program>
     */
    private function seedPrograms(string $propertyId, $catalog): array
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
            ['program' => 'stag', 'category' => 'guide', 'minimum' => 1, 'ratio' => 1, 'capabilities' => ['hunting'], 'languages' => ['en']],
            ['program' => 'double', 'category' => 'guide', 'minimum' => 1, 'ratio' => 2, 'capabilities' => ['fishing'], 'languages' => ['en']],
            ['program' => 'fishing', 'category' => 'guide', 'minimum' => 1, 'ratio' => 2, 'capabilities' => ['fishing'], 'languages' => []],
            ['program' => 'horseback', 'category' => 'horse', 'minimum' => 1, 'ratio' => 1, 'capabilities' => [], 'languages' => []],
            ['program' => 'trekking', 'category' => 'guide', 'minimum' => 1, 'ratio' => 6, 'capabilities' => ['trekking'], 'languages' => []],
        ] as $index => $requirement) {
            ProgramResourceRequirement::query()->updateOrCreate(
                ['program_id' => $programs[$requirement['program']]->id, 'resource_category_id' => $catalog[$requirement['category']]->id, 'sort_order' => $index],
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

    /**
     * @param  array<string, User>  $staff
     * @param  Collection<string, ResourceCategory>  $catalog
     * @return array<string, \App\Models\Resource>
     */
    private function seedResources(string $propertyId, array $staff, $catalog): array
    {
        $resources = [
            ['code' => '101', 'name' => 'Coihue Suite', 'category' => 'room', 'capacity' => 2, 'housekeeping_status' => HousekeepingStatus::Inspected],
            ['code' => '102', 'name' => 'Lenga Suite', 'category' => 'room', 'capacity' => 2, 'housekeeping_status' => HousekeepingStatus::Clean],
            ['code' => 'CABIN', 'name' => 'River Cabin', 'category' => 'room', 'capacity' => 4, 'housekeeping_status' => HousekeepingStatus::InProgress],
            ['code' => 'GUIDE-MATEO', 'name' => 'Mateo Rios', 'category' => 'guide', 'capacity' => 2, 'user_id' => $staff['guide']->id, 'attributes' => ['capabilities' => ['hunting', 'fishing'], 'languages' => ['es', 'en']]],
            ['code' => 'HORSES', 'name' => 'Horse pool', 'category' => 'horse', 'capacity' => 8, 'attributes' => ['capabilities' => ['trail', 'hunting']]],
            ['code' => 'BOAT-01', 'name' => 'Drift boat', 'category' => 'boat', 'capacity' => 3],
            ['code' => 'TRANSFER-01', 'name' => 'Transfer 4x4', 'category' => 'vehicle', 'capacity' => 6],
            ['code' => 'BUYOUT', 'name' => 'Full lodge buyout', 'category' => 'venue', 'capacity' => 1, 'is_buyout' => true],
        ];

        $created = [];
        foreach ($resources as $resource) {
            $created[$resource['code']] = Resource::query()->updateOrCreate(
                ['code' => $resource['code']],
                [
                    'property_id' => $propertyId,
                    'category_id' => $catalog[$resource['category']]->id,
                    'name' => $resource['name'],
                    'capacity' => $resource['capacity'],
                    'user_id' => $resource['user_id'] ?? null,
                    'attributes' => $resource['attributes'] ?? null,
                    'is_buyout' => $resource['is_buyout'] ?? false,
                    'housekeeping_status' => $resource['housekeeping_status'] ?? null,
                    'housekeeping_updated_at' => isset($resource['housekeeping_status']) ? now() : null,
                    'is_active' => true,
                ],
            );
        }

        return $created;
    }

    /** @param Collection<string, ResourceCategory> $catalog */
    private function seedBookingPoliciesAndRates(Property $property, Collection $catalog): void
    {
        $deposit = DepositPolicy::query()->updateOrCreate(
            ['property_id' => $property->id, 'name' => 'Standard 50% deposit'],
            [
                'requirement_type' => 'percentage',
                'percentage_basis_points' => 5000,
                'deposit_due_offset_days' => 0,
                'balance_due_offset_days' => 30,
                'confirmation_requires_payment' => false,
                'is_default' => true,
                'is_active' => true,
            ],
        );
        $cancellation = CancellationPolicy::query()->updateOrCreate(
            ['property_id' => $property->id, 'name' => 'Standard lodge cancellation'],
            ['summary' => 'Twenty percent retained inside thirty days; fifty percent inside fourteen days.', 'is_default' => true, 'is_active' => true],
        );
        foreach ([[30, 2000], [14, 5000], [0, 10000]] as $index => [$days, $retained]) {
            CancellationPolicyTier::query()->updateOrCreate(
                ['cancellation_policy_id' => $cancellation->id, 'days_before_arrival' => $days],
                ['retained_basis_points' => $retained, 'minimum_fee_minor' => 0, 'sort_order' => $index],
            );
        }
        $plan = RatePlan::query()->updateOrCreate(
            ['property_id' => $property->id, 'name' => 'Flexible lodge rate', 'currency' => 'USD'],
            [
                'deposit_policy_id' => $deposit->id,
                'cancellation_policy_id' => $cancellation->id,
                'minimum_occupancy' => 1,
                'maximum_occupancy' => 4,
                'inclusions' => ['Breakfast', 'Lodge operations support'],
                'is_active' => true,
            ],
        );
        RateRule::query()->updateOrCreate(
            ['rate_plan_id' => $plan->id, 'resource_category_id' => $catalog['room']->id, 'priority' => 0],
            ['price_type' => 'per_night', 'amount_minor' => 300_000, 'minimum_stay' => 1, 'stop_sell' => false],
        );
        TaxRule::query()->updateOrCreate(
            ['property_id' => $property->id, 'name' => 'Demo VAT'],
            ['calculation_type' => 'percentage', 'percentage_basis_points' => 1900, 'is_inclusive' => false, 'priority' => 0, 'is_active' => true],
        );
    }

    /**
     * @param  array<string, Program>  $programs
     * @param  array<string, \App\Models\Resource>  $resources
     * @param  array<string, User>  $staff
     */
    private function seedDemoOperations(
        Property $property,
        array $programs,
        array $resources,
        array $staff,
        CarbonImmutable $today,
    ): void {
        $arrivalGuest = Guest::query()->updateOrCreate(
            ['email' => 'arrival@example.com'],
            [
                'first_name' => 'Isabella',
                'last_name' => 'Walker',
                'phone' => '+1 555 0101',
                'language' => 'en',
                'preferences' => ['dietary' => ['Gluten-free', 'Severe shellfish allergy'], 'stay_place' => 'Quiet cabin'],
            ],
        );
        $inHouseGuest = Guest::query()->updateOrCreate(
            ['email' => 'inhouse@example.com'],
            [
                'first_name' => 'Martin',
                'last_name' => 'Alvarez',
                'phone' => '+54 9 11 5555 0199',
                'language' => 'es',
                'preferences' => ['dietary' => ['Vegetarian'], 'activities' => ['fly fishing']],
            ],
        );
        $buyoutGuest = Guest::query()->updateOrCreate(
            ['email' => 'buyout@example.com'],
            ['first_name' => 'Patagonia', 'last_name' => 'Expedition Group', 'language' => 'en'],
        );

        $arrival = Reservation::query()->updateOrCreate(
            ['confirmation_number' => 'RSV-DEMO-ARRIVAL'],
            [
                'property_id' => $property->id,
                'program_id' => $programs['stag']->id,
                'primary_guest_id' => $arrivalGuest->id,
                'status' => ReservationStatus::Confirmed,
                'starts_at' => $today->addHours(15),
                'ends_at' => $today->addDays(4)->addHours(11),
                'adults' => 2,
                'children' => 0,
                'currency' => 'USD',
                'subtotal_minor' => 1_250_000,
                'tax_minor' => 237_500,
                'total_minor' => 1_487_500,
                'source' => 'Virtuoso',
                'confirmed_at' => $today->subDays(30),
            ],
        );
        $arrival->allocations()->updateOrCreate(
            ['resource_id' => $resources['102']->id],
            ['status' => AllocationStatus::Confirmed, 'starts_at' => $arrival->starts_at, 'ends_at' => $arrival->ends_at, 'quantity' => 1],
        );

        $inHouse = Reservation::query()->updateOrCreate(
            ['confirmation_number' => 'RSV-DEMO-INHOUSE'],
            [
                'property_id' => $property->id,
                'program_id' => $programs['double']->id,
                'primary_guest_id' => $inHouseGuest->id,
                'status' => ReservationStatus::CheckedIn,
                'starts_at' => $today->subDay()->addHours(15),
                'ends_at' => $today->addDays(2)->addHours(11),
                'adults' => 2,
                'children' => 1,
                'currency' => 'USD',
                'subtotal_minor' => 1_840_000,
                'tax_minor' => 349_600,
                'total_minor' => 2_189_600,
                'source' => 'Direct',
                'confirmed_at' => $today->subDays(45),
                'actual_start_at' => $today->subDay()->addHours(15),
            ],
        );
        $inHouse->allocations()->updateOrCreate(
            ['resource_id' => $resources['CABIN']->id],
            ['status' => AllocationStatus::Confirmed, 'starts_at' => $inHouse->starts_at, 'ends_at' => $inHouse->ends_at, 'quantity' => 1],
        );

        $buyout = Reservation::query()->updateOrCreate(
            ['confirmation_number' => 'RSV-DEMO-BUYOUT'],
            [
                'property_id' => $property->id,
                'program_id' => $programs['stag']->id,
                'primary_guest_id' => $buyoutGuest->id,
                'status' => ReservationStatus::Confirmed,
                'starts_at' => $today->addDays(8)->addHours(15),
                'ends_at' => $today->addDays(12)->addHours(11),
                'adults' => 6,
                'children' => 0,
                'currency' => 'USD',
                'subtotal_minor' => 4_800_000,
                'tax_minor' => 912_000,
                'total_minor' => 5_712_000,
                'source' => 'Summit Travel',
                'confirmed_at' => $today->subDays(60),
            ],
        );
        $buyout->allocations()->updateOrCreate(
            ['resource_id' => $resources['BUYOUT']->id],
            ['status' => AllocationStatus::Confirmed, 'starts_at' => $buyout->starts_at, 'ends_at' => $buyout->ends_at, 'quantity' => 1],
        );

        $occurrences = [
            ['key' => 'fishing', 'point' => 'Rio Gallegos launch', 'start' => $today->addDay()->addHours(8), 'duration' => 8, 'capacity' => 4],
            ['key' => 'horseback', 'point' => 'Main corral', 'start' => $today->addDays(2)->addHours(9), 'duration' => 3, 'capacity' => 8],
            ['key' => 'trekking', 'point' => 'North trailhead', 'start' => $today->addDays(3)->addHours(8), 'duration' => 6, 'capacity' => 10],
        ];
        foreach ($occurrences as $definition) {
            $occurrence = ServiceOccurrence::query()->updateOrCreate(
                ['program_id' => $programs[$definition['key']]->id, 'meeting_point' => $definition['point']],
                [
                    'property_id' => $property->id,
                    'starts_at' => $definition['start'],
                    'ends_at' => $definition['start']->addHours($definition['duration']),
                    'capacity' => $definition['capacity'],
                    'is_cancelled' => false,
                ],
            );
            $resourceCodes = match ($definition['key']) {
                'fishing' => ['GUIDE-MATEO', 'BOAT-01'],
                'horseback' => ['HORSES'],
                default => ['GUIDE-MATEO'],
            };
            foreach ($resourceCodes as $resourceCode) {
                $arrival->allocations()->updateOrCreate(
                    ['service_occurrence_id' => $occurrence->id, 'resource_id' => $resources[$resourceCode]->id],
                    [
                        'status' => AllocationStatus::Confirmed,
                        'starts_at' => $occurrence->starts_at,
                        'ends_at' => $occurrence->ends_at,
                        'quantity' => $resourceCode === 'HORSES' ? 2 : 1,
                    ],
                );
            }
        }

        $arrivalPayment = Payment::query()->updateOrCreate(
            ['provider' => 'manual_seed', 'provider_reference' => 'DEMO-ARRIVAL-DEPOSIT'],
            [
                'reservation_id' => $arrival->id,
                'status' => PaymentStatus::Succeeded,
                'method' => 'bank_transfer',
                'currency' => 'USD',
                'amount_minor' => 743_750,
                'processed_at' => $today->subDays(20),
                'metadata' => ['evidence' => 'demo-wire-confirmation.pdf'],
            ],
        );
        $inHousePayment = Payment::query()->updateOrCreate(
            ['provider' => 'manual_seed', 'provider_reference' => 'DEMO-INHOUSE-PAID'],
            [
                'reservation_id' => $inHouse->id,
                'status' => PaymentStatus::Succeeded,
                'method' => 'bank_transfer',
                'currency' => 'USD',
                'amount_minor' => 2_189_600,
                'processed_at' => $today->subDays(5),
                'metadata' => ['evidence' => 'demo-paid-in-full.pdf'],
            ],
        );
        app(FolioService::class)->postPayment($arrivalPayment, null);
        app(FolioService::class)->postPayment($inHousePayment, null);
        Deposit::query()->updateOrCreate(
            ['reservation_id' => $arrival->id, 'schedule_type' => 'deposit'],
            [
                'payment_id' => $arrivalPayment->id,
                'status' => DepositStatus::Paid,
                'currency' => 'USD',
                'amount_minor' => 743_750,
                'due_at' => $today->subDays(21),
                'paid_at' => $today->subDays(20),
            ],
        );
        Deposit::query()->updateOrCreate(
            ['reservation_id' => $arrival->id, 'schedule_type' => 'balance'],
            [
                'payment_id' => null,
                'status' => DepositStatus::Due,
                'currency' => 'USD',
                'amount_minor' => 743_750,
                'due_at' => $today->subDay(),
                'paid_at' => null,
            ],
        );
        Deposit::query()->updateOrCreate(
            ['reservation_id' => $inHouse->id, 'schedule_type' => 'balance'],
            [
                'payment_id' => $inHousePayment->id,
                'status' => DepositStatus::Paid,
                'currency' => 'USD',
                'amount_minor' => 2_189_600,
                'due_at' => $today->subDays(30),
                'paid_at' => $today->subDays(5),
            ],
        );
        FolioLine::query()->firstOrCreate(
            ['reservation_id' => $inHouse->id, 'description' => 'Private fly-fishing transfer'],
            [
                'type' => FolioLineType::Charge,
                'quantity' => 1,
                'unit_amount_minor' => 185_000,
                'net_amount_minor' => 185_000,
                'tax_amount_minor' => 0,
                'gross_amount_minor' => 185_000,
                'amount_minor' => 185_000,
                'currency' => 'USD',
                'posted_at' => $today->addHours(18),
                'metadata' => ['category' => 'extra'],
            ],
        );

        CostRecord::query()->updateOrCreate(
            ['reservation_id' => $arrival->id, 'description' => 'Guide and field provisions'],
            [
                'program_id' => $arrival->program_id,
                'staff_user_id' => $staff['guide']->id,
                'kind' => 'actual',
                'category' => 'guide',
                'currency' => 'USD',
                'amount_minor' => 175_000,
                'occurred_at' => $today,
            ],
        );
        CommissionAccrual::query()->updateOrCreate(
            ['reservation_id' => $arrival->id, 'payee_name' => 'Virtuoso'],
            [
                'payee_type' => 'channel',
                'rate_basis_points' => 1_000,
                'base_amount_minor' => $arrival->total_minor,
                'amount_minor' => 148_750,
                'currency' => 'USD',
                'status' => 'accrued',
            ],
        );

        foreach ([
            ['reservation' => $arrival, 'title' => 'Confirm Isabella airport transfer', 'role' => 'operations', 'priority' => 'urgent', 'due' => $today->addHours(10)],
            ['reservation' => $arrival, 'title' => 'Brief guide on Red Stag itinerary', 'role' => 'guide', 'priority' => 'high', 'due' => $today->addHours(11)],
            ['reservation' => $arrival, 'title' => 'Prepare allergy-safe welcome dinner', 'role' => 'kitchen', 'priority' => 'high', 'due' => $today->addHours(12)],
            ['reservation' => $inHouse, 'title' => 'Post private fishing transfer extra', 'role' => 'finance', 'priority' => 'normal', 'due' => $today->addHours(18)],
        ] as $task) {
            OperationalTask::query()->updateOrCreate(
                ['reservation_id' => $task['reservation']->id, 'title' => $task['title']],
                [
                    'property_id' => $property->id,
                    'status' => TaskStatus::Todo,
                    'priority' => $task['priority'],
                    'due_at' => $task['due'],
                    'metadata' => ['role' => $task['role']],
                ],
            );
        }
    }

    /**
     * @param  array<string, Program>  $programs
     * @param  array<string, \App\Models\Resource>  $resources
     * @param  array<string, User>  $staff
     */
    private function seedDemoTrends(
        Property $property,
        array $programs,
        array $resources,
        array $staff,
        CarbonImmutable $today,
    ): void {
        $programKeys = ['stay', 'stag', 'double'];
        $roomCodes = ['101', '102', 'CABIN'];
        $sources = ['Direct', 'Virtuoso', 'Summit Travel'];
        $dayOffsets = [2, 11, 20];

        // Historical trend rows intentionally stop before the current month. Current
        // operations above already occupy live inventory and should remain conflict-free.
        foreach (range(7, 1) as $monthsAgo) {
            $month = $today->subMonths($monthsAgo)->startOfMonth();

            foreach ($dayOffsets as $index => $dayOffset) {
                $sequence = ((7 - $monthsAgo) * count($dayOffsets)) + $index + 1;
                $startsAt = $month->addDays($dayOffset)->addHours(15);
                $endsAt = $startsAt->addDays(2 + ($index % 2))->subHours(4);
                $program = $programs[$programKeys[$index]];
                $total = 320_000 + (($monthsAgo + 1) * 45_000) + ($index * 90_000);
                $guest = Guest::query()->updateOrCreate(
                    ['email' => sprintf('trend-%02d@example.com', $sequence)],
                    [
                        'first_name' => ['Elena', 'James', 'Camila'][$index],
                        'last_name' => 'Demo '.$sequence,
                        'language' => $index === 0 ? 'es' : 'en',
                        'preferences' => ['dietary' => [$index === 1 ? 'Vegetarian' : 'No restrictions']],
                    ],
                );
                $reservation = Reservation::query()->updateOrCreate(
                    ['confirmation_number' => sprintf('RSV-TREND-%02d', $sequence)],
                    [
                        'property_id' => $property->id,
                        'program_id' => $program->id,
                        'primary_guest_id' => $guest->id,
                        'status' => ReservationStatus::CheckedOut,
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                        'adults' => 2 + ($index % 2),
                        'children' => $index === 2 ? 1 : 0,
                        'currency' => 'USD',
                        'subtotal_minor' => $total,
                        'tax_minor' => (int) round($total * 0.19),
                        'total_minor' => (int) round($total * 1.19),
                        'source' => $sources[$index],
                        'confirmed_at' => $startsAt->subDays(30),
                        'actual_start_at' => $startsAt,
                        'actual_end_at' => $endsAt,
                    ],
                );
                $reservation->allocations()->updateOrCreate(
                    ['resource_id' => $resources[$roomCodes[$index]]->id],
                    [
                        'status' => AllocationStatus::Confirmed,
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                        'quantity' => 1,
                    ],
                );

                $paymentRatio = [1.0, 0.6, 0.0][$index];
                if ($paymentRatio > 0) {
                    $payment = Payment::query()->updateOrCreate(
                        ['provider' => 'manual_seed', 'provider_reference' => sprintf('TREND-PAYMENT-%02d', $sequence)],
                        [
                            'reservation_id' => $reservation->id,
                            'status' => PaymentStatus::Succeeded,
                            'method' => $index === 0 ? 'card' : 'bank_transfer',
                            'currency' => 'USD',
                            'amount_minor' => (int) round($reservation->total_minor * $paymentRatio),
                            'processed_at' => $month->addDays(1 + ($index * 7))->addHours(10),
                            'metadata' => ['scenario' => 'dashboard_trend'],
                        ],
                    );
                    app(FolioService::class)->postPayment($payment, null);
                    if ($paymentRatio === 1.0 && $reservation->status === ReservationStatus::CheckedOut) {
                        app(FolioService::class)->close($reservation, null);
                    }
                }

                CostRecord::query()->updateOrCreate(
                    ['reservation_id' => $reservation->id, 'description' => 'Trend demo operating cost'],
                    [
                        'program_id' => $program->id,
                        'staff_user_id' => $staff['guide']->id,
                        'kind' => 'actual',
                        'category' => 'operations',
                        'currency' => 'USD',
                        'amount_minor' => (int) round($reservation->total_minor * (0.22 + ($index * 0.03))),
                        'occurred_at' => $startsAt,
                    ],
                );

                if ($index > 0) {
                    CommissionAccrual::query()->updateOrCreate(
                        ['reservation_id' => $reservation->id, 'payee_name' => $sources[$index]],
                        [
                            'payee_type' => 'channel',
                            'rate_basis_points' => 1_000,
                            'base_amount_minor' => $reservation->total_minor,
                            'amount_minor' => (int) round($reservation->total_minor * 0.10),
                            'currency' => 'USD',
                            'status' => 'accrued',
                        ],
                    );
                }
            }
        }
    }

    /**
     * Seed enough connected commercial and guest-experience data for every demo
     * module to tell a truthful story without requiring users to manufacture rows.
     *
     * @param  array<string, Program>  $programs
     * @param  array<string, User>  $staff
     */
    private function seedDemoSalesAndGuestExperience(
        Property $property,
        array $programs,
        array $staff,
        Reservation $reservation,
        Guest $guest,
        CarbonImmutable $today,
    ): void {
        $organization = Organization::query()->updateOrCreate(
            ['name' => 'Andes Signature Travel', 'type' => 'agency'],
            [
                'email' => 'partners@andessignature.example',
                'phone' => '+54 9 11 5555 0142',
                'commission_basis_points' => 1_000,
                'metadata' => ['market' => 'Latin America', 'segment' => 'luxury leisure'],
                'is_active' => true,
            ],
        );
        $proposal = Proposal::query()->updateOrCreate(
            ['reference' => 'Q-DEMO-001', 'version' => 1],
            [
                'reservation_id' => null,
                'property_id' => $property->id,
                'primary_guest_id' => $guest->id,
                'starts_at' => $today->addDays(45)->addHours(15),
                'ends_at' => $today->addDays(49)->addHours(11),
                'adults' => 2,
                'children' => 0,
                'status' => ProposalStatus::Draft,
                'currency' => 'USD',
                'total_minor' => 1_487_500,
                'tax_minor' => 237_500,
                'snapshot' => [
                    'title' => 'Private Patagonia return stay',
                    'program_id' => $programs['stag']->id,
                    'notes' => 'Agency-requested private guide and airport transfer.',
                    'subtotal_minor' => 1_250_000,
                    'lines' => [[
                        'description' => 'Red Stag Hunting package',
                        'quantity_thousandths' => 1_000,
                        'unit_amount_minor' => 1_250_000,
                    ]],
                ],
                'expires_at' => $today->addDays(14),
                'created_by' => $staff['sales']->id,
            ],
        );
        $opportunity = Opportunity::query()->updateOrCreate(
            ['title' => 'Sofia Martinez · private return stay'],
            [
                'property_id' => $property->id,
                'guest_id' => $guest->id,
                'organization_id' => $organization->id,
                'proposal_id' => $proposal->id,
                'owner_id' => $staff['sales']->id,
                'stage' => 'proposal',
                'source' => 'Agency referral',
                'currency' => 'USD',
                'value_minor' => 1_487_500,
                'expected_close_on' => $today->addDays(10),
                'lost_reason' => null,
            ],
        );
        CrmActivity::query()->updateOrCreate(
            ['opportunity_id' => $opportunity->id, 'subject' => 'Review private-guide proposal with agency'],
            [
                'guest_id' => $guest->id,
                'actor_id' => $staff['sales']->id,
                'type' => 'call',
                'body' => 'Confirm guest dates, transfer window, and preferred guide before acceptance.',
                'due_at' => $today->addDays(2)->addHours(14),
                'completed_at' => null,
            ],
        );

        GuestPortalProfile::query()->updateOrCreate(
            ['reservation_id' => $reservation->id, 'guest_id' => $guest->id],
            [
                'profile' => ['first_name' => 'Sofia', 'last_name' => 'Martinez', 'language' => 'es'],
                'travel' => ['arrival_method' => 'flight', 'arrival_reference' => 'AR 1890'],
                'preferences' => [
                    'dietary' => ['Vegetarian'],
                    'allergies' => ['Severe nut allergy'],
                    'dietary_style' => 'Vegetarian',
                    'activities' => ['horseback riding'],
                ],
                'consented_at' => $today->subDay(),
            ],
        );
        Survey::query()->updateOrCreate(
            ['reservation_id' => $reservation->id, 'guest_id' => $guest->id, 'kind' => 'pre_arrival'],
            [
                'score' => 5,
                'answers' => ['priority' => 'Quiet stay and guided horseback ride', 'contact_preference' => 'email'],
                'sent_at' => $today->subDays(3),
                'responded_at' => $today->subDay(),
            ],
        );

        ExchangeRate::query()->firstOrCreate(
            [
                'property_id' => $property->id,
                'base_currency' => 'ARS',
                'quote_currency' => 'USD',
                'effective_at' => $today->startOfYear(),
            ],
            ['rate' => '0.0011000000', 'source' => 'Demo accounting snapshot'],
        );

        $arsStartsAt = $today->subMonths(8)->startOfMonth()->addDays(10)->addHours(15);
        $arsEndsAt = $arsStartsAt->addDays(3)->subHours(4);
        $arsReservation = Reservation::query()->updateOrCreate(
            ['confirmation_number' => 'RSV-DEMO-ARS'],
            [
                'property_id' => $property->id,
                'program_id' => $programs['stay']->id,
                'primary_guest_id' => $guest->id,
                'status' => ReservationStatus::CheckedOut,
                'starts_at' => $arsStartsAt,
                'ends_at' => $arsEndsAt,
                'adults' => 2,
                'children' => 0,
                'currency' => 'ARS',
                'subtotal_minor' => 95_000_000,
                'tax_minor' => 18_050_000,
                'total_minor' => 113_050_000,
                'source' => 'Direct Argentina',
                'confirmed_at' => $arsStartsAt->subDays(30),
                'actual_start_at' => $arsStartsAt,
                'actual_end_at' => $arsEndsAt,
            ],
        );
        $arsReservation->allocations()->updateOrCreate(
            ['resource_id' => Resource::query()->where('code', '101')->firstOrFail()->id],
            [
                'status' => AllocationStatus::Confirmed,
                'starts_at' => $arsStartsAt,
                'ends_at' => $arsEndsAt,
                'quantity' => 1,
            ],
        );
        $arsPayment = Payment::query()->updateOrCreate(
            ['provider' => 'manual_seed', 'provider_reference' => 'DEMO-ARS-PAID'],
            [
                'reservation_id' => $arsReservation->id,
                'status' => PaymentStatus::Succeeded,
                'method' => 'bank_transfer',
                'currency' => 'ARS',
                'amount_minor' => $arsReservation->total_minor,
                'processed_at' => $arsEndsAt,
                'metadata' => ['scenario' => 'multi_currency_demo'],
            ],
        );
        app(FolioService::class)->postPayment($arsPayment, null);
        app(FolioService::class)->close($arsReservation, null);
        CostRecord::query()->updateOrCreate(
            ['reservation_id' => $arsReservation->id, 'description' => 'ARS demo operating cost'],
            [
                'program_id' => $arsReservation->program_id,
                'staff_user_id' => $staff['guide']->id,
                'kind' => 'actual',
                'category' => 'operations',
                'currency' => 'ARS',
                'amount_minor' => 28_000_000,
                'occurred_at' => $arsEndsAt,
            ],
        );
    }

    private function seedAutomation(): void
    {
        $rules = [
            ['name' => 'Reservation confirmation and private portal', 'trigger' => 'reservation.confirmed', 'actions' => [[
                'type' => 'guest_portal_invitation',
                'template_key' => 'confirmation',
                'purpose' => 'pre_arrival',
                'subject' => 'Your reservation {{reservation.confirmation_number}} is confirmed',
                'body' => 'Your reservation is confirmed. Complete your travel details, documents, and payment evidence here: {{guest_portal.url}}',
            ]]],
            ['name' => 'Arrival preparation reminder', 'trigger' => 'reservation.arrival_approaching', 'actions' => [[
                'type' => 'queue_communication',
                'template_key' => 'arrival',
                'subject' => 'Your lodge arrival is approaching',
                'body' => 'Your arrival is {{payload.days_before}} day(s) away. Please review your travel details and contact us with any changes.',
            ]]],
            ['name' => 'Overdue deposit reminder', 'trigger' => 'deposit.overdue', 'actions' => [[
                'type' => 'deposit_reminder',
                'template_key' => 'payment',
                'subject' => 'Reservation payment reminder',
                'body' => 'A payment of {{deposit.amount_minor}} {{deposit.currency}} is overdue. Please reply with your bank transfer confirmation.',
            ]]],
            ['name' => 'Post-stay survey invitation', 'trigger' => 'reservation.checkout_completed', 'actions' => [[
                'type' => 'guest_portal_invitation',
                'template_key' => 'survey',
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
            'confirmation' => ['Reservation confirmation', 'Your reservation {{reservation.confirmation_number}} is confirmed. Complete your travel details, documents, and payment evidence here: {{guest_portal.url}}'],
            'payment' => ['Payment instructions', 'A payment of {{deposit.amount_minor}} {{deposit.currency}} is due. Please complete your bank transfer and upload the confirmation.'],
            'pre_arrival' => ['Pre-arrival recommendations', 'Review travel, clothing, and activity recommendations before departure.'],
            'arrival' => ['Arrival instructions', 'Your arrival is {{payload.days_before}} day(s) away. Your transfer and check-in details are ready.'],
            'survey' => ['Thank you and survey', 'Thank you for visiting. Share your feedback through your private reservation center: {{guest_portal.url}}'],
        ] as $key => [$name, $body]) {
            $template = MessageTemplate::query()->updateOrCreate(
                ['key' => $key, 'channel' => 'email'],
                ['name' => $name, 'is_active' => true],
            );
            $currentVersion = $template->versions()->where('language', 'en')->latest('version')->first();
            if ($currentVersion === null || $currentVersion->subject !== $name || $currentVersion->body !== $body) {
                $template->versions()->create([
                    'version' => ((int) $template->versions()->max('version')) + 1,
                    'language' => 'en',
                    'subject' => $name,
                    'body' => $body,
                    'published_at' => now(),
                ]);
            }
        }
    }
}
