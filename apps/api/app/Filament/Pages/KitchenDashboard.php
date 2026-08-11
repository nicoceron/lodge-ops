<?php

namespace App\Filament\Pages;

use App\Enums\MembershipRole;
use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Support\Projections\StaffProjectionVisibility;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class KitchenDashboard extends Page
{
    protected string $view = 'filament.pages.kitchen-dashboard';

    protected static ?string $title = 'Kitchen dashboard';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-fire';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 3;

    public string $start = '';

    public string $end = '';

    public ?string $propertyId = null;

    public function mount(): void
    {
        $context = app(TenantContext::class);
        $membershipPropertyId = $context->membership()?->property_id;
        $this->propertyId = $membershipPropertyId
            ?? $context->tenant()->properties()->where('is_active', true)->orderBy('name')->value('id');

        $timezone = $this->selectedProperty($context)?->timezone ?: $context->tenant()->timezone;
        $today = CarbonImmutable::now($timezone)->toDateString();
        $this->start = $today;
        $this->end = $today;
    }

    public static function canAccess(): bool
    {
        return app(TenantContext::class)->membership()?->role?->canManageOperations() === true
            && app(StaffProjectionVisibility::class)->canSeeDietaryDetails();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $context = app(TenantContext::class);
        $membership = $context->membership();
        abort_unless($membership?->role instanceof MembershipRole, 403);
        abort_unless(app(StaffProjectionVisibility::class)->canSeeDietaryDetails(), 403);

        $property = $this->selectedProperty($context);
        $timezone = $property?->timezone ?: $context->tenant()->timezone;
        [$localStart, $localEnd, $start, $end] = $this->planningRange($timezone);
        $propertyId = $property?->id;

        $reservations = Reservation::query()
            ->with([
                'primaryGuest:id,preferences',
                'guests:id,preferences',
                'property:id,name',
            ])
            ->when($propertyId, fn (Builder $query) => $query->where('property_id', $propertyId))
            ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn])
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->orderBy('starts_at')
            ->get();

        $items = $reservations->map(fn (Reservation $reservation): array => $this->reservationItem($reservation, $timezone));
        $restrictions = $this->restrictions($reservations);

        return [
            'timezone' => $timezone,
            'property' => $property,
            'properties' => $this->properties($context),
            'startDate' => $localStart,
            'endDate' => $localEnd,
            'reservations' => $items,
            'guestCount' => (int) $reservations->sum(fn (Reservation $reservation): int => $reservation->adults + $reservation->children),
            'reservationCount' => $reservations->count(),
            'restrictions' => $restrictions,
        ];
    }

    private function selectedProperty(TenantContext $context): ?Property
    {
        $membershipPropertyId = $context->membership()?->property_id;
        $requestedPropertyId = $membershipPropertyId ?? $this->propertyId;

        $query = Property::query()->where('is_active', true);
        if ($requestedPropertyId !== null) {
            $query->whereKey($requestedPropertyId);
        }

        return $query->first()
            ?? ($membershipPropertyId === null
                ? Property::query()->where('is_active', true)->orderBy('name')->first()
                : null);
    }

    /** @return Collection<int, Property> */
    private function properties(TenantContext $context): Collection
    {
        $propertyId = $context->membership()?->property_id;

        return Property::query()
            ->where('is_active', true)
            ->when($propertyId, fn (Builder $query) => $query->whereKey($propertyId))
            ->orderBy('name')
            ->get(['id', 'name', 'timezone']);
    }

    /** @return array{CarbonImmutable, CarbonImmutable, CarbonImmutable, CarbonImmutable} */
    private function planningRange(string $timezone): array
    {
        $parse = static function (?string $value) use ($timezone): ?CarbonImmutable {
            if ($value === null || trim($value) === '') {
                return null;
            }

            try {
                return CarbonImmutable::createFromFormat('!Y-m-d', trim($value), $timezone)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        };

        $localStart = $parse($this->start) ?? CarbonImmutable::now($timezone)->startOfDay();
        $localEnd = $parse($this->end) ?? $localStart;

        if ($localEnd->lessThan($localStart)) {
            $localEnd = $localStart;
        }

        if ($localStart->diffInDays($localEnd) > 92) {
            $localEnd = $localStart->addDays(92);
        }

        return [$localStart, $localEnd, $localStart->utc(), $localEnd->addDay()->startOfDay()->utc()];
    }

    /** @return array<string, mixed> */
    private function reservationItem(Reservation $reservation, string $timezone): array
    {
        $guests = $this->reservationGuests($reservation);

        return [
            'reference' => $reservation->confirmation_number,
            'starts_at' => $reservation->starts_at->timezone($timezone),
            'ends_at' => $reservation->ends_at->timezone($timezone),
            'party' => $reservation->adults + $reservation->children,
            'dietary' => $guests->flatMap(fn (Guest $guest) => $this->dietaryLabels($guest->preferences))
                ->unique(fn (string $label): string => strtolower($label))
                ->values()
                ->all(),
        ];
    }

    /** @param Collection<int, Reservation> $reservations @return list<array<string, mixed>> */
    private function restrictions(Collection $reservations): array
    {
        return $reservations
            ->flatMap(fn (Reservation $reservation) => $this->reservationGuests($reservation)
                ->flatMap(fn (Guest $guest) => $this->dietaryLabels($guest->preferences)))
            ->countBy()
            ->map(fn (int $count, string $label): array => [
                'label' => $label,
                'count' => $count,
                'serious' => str_contains(strtolower($label), 'allerg')
                    || str_contains(strtolower($label), 'celiac')
                    || str_contains(strtolower($label), 'severe'),
            ])
            ->values()
            ->all();
    }

    /** @return Collection<int, Guest> */
    private function reservationGuests(Reservation $reservation): Collection
    {
        return collect([$reservation->primaryGuest])
            ->filter()
            ->concat($reservation->guests)
            ->unique('id')
            ->values();
    }

    /** @param array<string, mixed>|null $preferences @return list<string> */
    private function dietaryLabels(?array $preferences): array
    {
        if ($preferences === null) {
            return [];
        }

        $values = collect([
            data_get($preferences, 'dietary'),
            data_get($preferences, 'dietary_requirements'),
            data_get($preferences, 'allergies'),
        ])->filter()->flatMap(function (mixed $value): array {
            if (is_array($value)) {
                return array_values(array_filter($value, 'is_string'));
            }

            return is_string($value) ? preg_split('/[,;]+/', $value) ?: [] : [];
        });

        return $values
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->unique(fn (string $value): string => strtolower($value))
            ->values()
            ->all();
    }
}
