<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\ReservationStatus;
use App\Filament\Resources\SurveyResponses\Pages\ListSurveyResponses;
use App\Filament\Resources\SurveyResponses\SurveyResponseResource;
use App\Models\Membership;
use App\Models\Program;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Survey;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class FilamentSurveyResponseTrackerTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        Filament::setTenant(null, isQuiet: true);
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    public function test_management_can_filter_survey_responses_by_property_program_date_rating_and_read_comments(): void
    {
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment(MembershipRole::Manager, authenticate: false);
        $property->update(['name' => 'Main Lodge']);
        $membership->update(['property_id' => null]);
        app(TenantContext::class)->set($tenant, $membership->fresh());
        $otherProperty = Property::factory()->create(['name' => 'South Ridge Lodge']);
        $program = $this->program($property, 'Andean Escape');
        $otherProgram = $this->program($otherProperty, 'Forest Weekend');
        $targetReservation = $this->reservation($property, $program, 'RSV-SURVEY-TARGET');
        $otherReservation = $this->reservation($otherProperty, $otherProgram, 'RSV-SURVEY-OTHER');
        $target = $this->survey($targetReservation, 5, '2026-08-12 15:30:00 UTC', 'Exceptional service from the lodge team.');
        $other = $this->survey($otherReservation, 2, '2026-08-13 15:30:00 UTC', 'Needs improvement.');
        $this->prepareFilament($tenant, $membership->fresh(), $user);

        Livewire::test(ListSurveyResponses::class)
            ->assertCanSeeTableRecords([$target, $other])
            ->assertTableFilterExists('property')
            ->assertTableFilterExists('program')
            ->assertTableFilterExists('date')
            ->assertTableFilterExists('rating')
            ->filterTable('property', $property->id)
            ->filterTable('program', $program->id)
            ->filterTable('date', ['from' => '2026-08-12', 'until' => '2026-08-12'])
            ->filterTable('rating', 5)
            ->assertCanSeeTableRecords([$target])
            ->assertCanNotSeeTableRecords([$other])
            ->assertSee('Exceptional service from the lodge team.')
            ->assertSee('Andean Escape')
            ->assertSee('Main Lodge');
    }

    public function test_property_scoped_service_staff_only_see_responses_for_their_property_and_kitchen_and_guides_are_denied(): void
    {
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment(MembershipRole::Operations, authenticate: false);
        $otherProperty = Property::factory()->create(['name' => 'South Ridge Lodge']);
        $own = $this->survey($this->reservation($property, null, 'RSV-SURVEY-OWN'), 4, '2026-08-12 15:30:00 UTC', 'Own property response.');
        $other = $this->survey($this->reservation($otherProperty, null, 'RSV-SURVEY-CROSS-PROPERTY'), 5, '2026-08-12 15:30:00 UTC', 'Cross-property response.');
        $this->prepareFilament($tenant, $membership, $user);

        $this->assertTrue(SurveyResponseResource::canViewAny());
        $this->assertSame([$own->id], SurveyResponseResource::getEloquentQuery()->pluck('id')->all());
        $this->assertFalse(SurveyResponseResource::canView($other));

        $kitchenUser = User::factory()->create();
        $kitchenMembership = Membership::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $kitchenUser->id,
            'property_id' => $property->id,
            'role' => MembershipRole::Kitchen,
        ]);
        app(TenantContext::class)->set($tenant, $kitchenMembership);
        $this->actingAs($kitchenUser);
        $this->assertFalse(SurveyResponseResource::canViewAny());

        $guideUser = User::factory()->create();
        $guideMembership = Membership::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $guideUser->id,
            'property_id' => $property->id,
            'role' => MembershipRole::Guide,
        ]);
        app(TenantContext::class)->set($tenant, $guideMembership);
        $this->actingAs($guideUser);
        $this->assertFalse(SurveyResponseResource::canViewAny());
    }

    public function test_staff_can_open_a_read_only_survey_response_detail_with_comments(): void
    {
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment(MembershipRole::Sales, authenticate: false);
        $survey = $this->survey(
            $this->reservation($property, $this->program($property, 'Trail Service'), 'RSV-SURVEY-DETAIL'),
            5,
            '2026-08-12 15:30:00 UTC',
            'Please pass along our thanks to the service team.',
        );
        $this->prepareFilament($tenant, $membership, $user);

        $this->get(SurveyResponseResource::getUrl('view', ['tenant' => $tenant, 'record' => $survey]))
            ->assertOk()
            ->assertSee('Please pass along our thanks to the service team.')
            ->assertSee('Trail Service')
            ->assertSee('Response date')
            ->assertSee('Rating');

        $this->assertSame(['index', 'view'], array_keys(SurveyResponseResource::getPages()));
        $this->assertFalse(SurveyResponseResource::canCreate());
        $this->assertFalse(SurveyResponseResource::canEdit($survey));
    }

    private function program(Property $property, string $name): Program
    {
        return Program::query()->create([
            'property_id' => $property->id,
            'name' => $name,
            'display_color' => '#8C4438',
            'requires_accommodation' => true,
            'default_duration_minutes' => 1440,
            'capacity' => 6,
            'price_minor' => 100000,
            'currency' => 'COP',
            'is_active' => true,
        ]);
    }

    private function reservation(Property $property, ?Program $program, string $confirmation): Reservation
    {
        return Reservation::factory()->create([
            'property_id' => $property->id,
            'program_id' => $program?->id,
            'confirmation_number' => $confirmation,
            'status' => ReservationStatus::CheckedOut,
            'starts_at' => '2026-08-10 15:00:00 UTC',
            'ends_at' => '2026-08-12 15:00:00 UTC',
        ]);
    }

    private function survey(Reservation $reservation, int $score, string $respondedAt, string $comment): Survey
    {
        return Survey::query()->create([
            'reservation_id' => $reservation->id,
            'guest_id' => null,
            'kind' => 'post_stay',
            'score' => $score,
            'answers' => [
                'stay_rating' => $score,
                'guide_rating' => max(1, $score - 1),
                'comment' => $comment,
                'share_with_team' => true,
            ],
            'responded_at' => $respondedAt,
        ]);
    }

    private function prepareFilament(object $tenant, object $membership, object $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(filament()->getPanel('admin'));
        Filament::setTenant($tenant, isQuiet: true);
        app(TenantContext::class)->set($tenant, $membership);
    }
}
