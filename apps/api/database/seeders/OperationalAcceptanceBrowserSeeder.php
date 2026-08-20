<?php

namespace Database\Seeders;

use App\Enums\AllocationStatus;
use App\Enums\ReservationStatus;
use App\Models\Allocation;
use App\Models\ChecklistTemplate;
use App\Models\Guest;
use App\Models\Membership;
use App\Models\Program;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ResourceCategory;
use App\Models\Tenant;
use App\Services\ChecklistWorkflowService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class OperationalAcceptanceBrowserSeeder extends Seeder
{
    public const RESERVATION_REFERENCE = 'RSV-OP-REVIEW-UAT';

    public const CHECKLIST_NAME = 'Operational review UAT';

    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'demo-lodge')->firstOrFail();
        $context = app(TenantContext::class);
        $context->set($tenant);

        try {
            $property = Property::query()->where('code', 'MAIN')->firstOrFail();
            $manager = Membership::query()->whereHas('user', fn ($query) => $query->where('email', 'admin@example.com'))
                ->where('is_active', true)->firstOrFail();
            $context->set($tenant, $manager);
            $program = Program::query()->where('property_id', $property->id)->where('name', 'Fly fishing')->firstOrFail();
            $guideCategory = ResourceCategory::query()->where('property_id', $property->id)->where('slug', 'guide')->firstOrFail();
            $guest = Guest::query()->updateOrCreate(
                ['email' => 'operational-review-uat@example.test'],
                ['first_name' => 'Operational', 'last_name' => 'Review UAT', 'language' => 'en'],
            );
            $startsAt = CarbonImmutable::now($property->timezone)->startOfDay()->addDays(5)->addHours(8)->utc();
            $reservation = Reservation::query()->updateOrCreate(
                ['confirmation_number' => self::RESERVATION_REFERENCE],
                [
                    'property_id' => $property->id,
                    'program_id' => $program->id,
                    'primary_guest_id' => $guest->id,
                    'status' => ReservationStatus::Confirmed,
                    'starts_at' => $startsAt,
                    'ends_at' => $startsAt->addHours(6),
                    'adults' => 1,
                    'children' => 0,
                    'currency' => 'USD',
                    'subtotal_minor' => 120_000,
                    'tax_minor' => 22_800,
                    'total_minor' => 142_800,
                    'source' => 'operational-acceptance-browser',
                    'confirmed_at' => now(),
                ],
            );
            $reservation->checklistExceptions()->delete();
            Allocation::query()->where('reservation_id', $reservation->id)
                ->whereNotNull('resource_id')
                ->whereHas('resource', fn ($query) => $query->where('category_id', $guideCategory->id))
                ->update(['status' => AllocationStatus::Released]);
            Allocation::query()->updateOrCreate(
                [
                    'reservation_id' => $reservation->id,
                    'requested_category_id' => $guideCategory->id,
                    'resource_id' => null,
                ],
                [
                    'status' => AllocationStatus::Confirmed,
                    'starts_at' => $reservation->starts_at,
                    'ends_at' => $reservation->ends_at,
                    'quantity' => 1,
                ],
            );

            $template = ChecklistTemplate::query()->firstOrCreate(
                ['property_id' => $property->id, 'program_id' => $program->id, 'name' => self::CHECKLIST_NAME],
                ['role' => 'operations'],
            );
            if (! $template->versions()->where('state', 'published')->exists()) {
                app(ChecklistWorkflowService::class)->publish($template, [
                    ['title' => 'Confirm trail briefing', 'priority' => 'normal', 'due_offset_minutes' => -60],
                ], $manager->user_id);
            }
        } finally {
            $context->clear();
        }
    }
}
