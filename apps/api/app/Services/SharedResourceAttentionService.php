<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\ReservationStatus;
use App\Models\Allocation;
use App\Models\Reservation;
use App\Models\ResourceCategory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class SharedResourceAttentionService
{
    public function __construct(private readonly ResourceSuggestionService $suggestions) {}

    /** @param list<string> $conflictReservationIds @return Collection<int, array<string, mixed>> */
    public function build(CarbonImmutable $start, CarbonImmutable $end, ?string $propertyId, array $conflictReservationIds): Collection
    {
        $reservations = Reservation::query()
            ->with(['program.requirements.category', 'allocations.resource.category', 'allocations.requestedCategory'])
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->whereIn('status', [ReservationStatus::Hold, ReservationStatus::Confirmed, ReservationStatus::CheckedIn])
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->get();

        return $reservations->flatMap(function (Reservation $reservation) use ($conflictReservationIds): array {
            $party = max(1, $reservation->adults + $reservation->children);
            $requirements = collect($reservation->program === null ? [] : $reservation->program->requirements)
                ->filter(fn ($requirement) => $this->isSharedOperationalCategory($requirement->category));
            $categories = $requirements->mapWithKeys(fn ($requirement) => [$requirement->resource_category_id => [
                'category' => $requirement->category,
                'required' => $requirement->quantityForParty($party),
                'capabilities' => $requirement->capabilities ?? [],
                'languages' => $requirement->languages ?? [],
            ]]);
            foreach ($reservation->allocations as $allocation) {
                $category = $allocation->requestedCategory ?? $allocation->resource?->category;
                if ($category !== null && $this->isSharedOperationalCategory($category) && ! $categories->has($category->id)) {
                    $categories->put($category->id, ['category' => $category, 'required' => 1, 'capabilities' => [], 'languages' => []]);
                }
            }

            return $categories->map(function (array $requirement) use ($reservation, $conflictReservationIds): ?array {
                /** @var ResourceCategory $category */
                $category = $requirement['category'];
                $active = $reservation->allocations->filter(fn (Allocation $allocation): bool => $allocation->status !== AllocationStatus::Released
                    && ($allocation->requested_category_id ?? $allocation->resource?->category_id) === $category->id);
                $assigned = (int) $active->whereNotNull('resource_id')->sum('quantity');
                $required = (int) $requirement['required'];
                $conflicted = in_array($reservation->id, $conflictReservationIds, true);
                if ($assigned >= $required && ! $conflicted) {
                    return null;
                }
                $allocation = $active->first();
                $ranked = array_values(array_filter([
                    $conflicted ? '1. Hard capacity, block, or property-wide buyout conflict.' : null,
                    $assigned < $required ? '2. Required '.$category->name.': '.$required.'; assigned '.$assigned.'.' : null,
                    $active->contains(fn (Allocation $item): bool => $item->resource_id === null) ? '3. Category request is awaiting an exact instance.' : null,
                ]));
                $allocationStart = $allocation instanceof Allocation ? $allocation->starts_at : $reservation->starts_at;
                $allocationEnd = $allocation instanceof Allocation ? $allocation->ends_at : $reservation->ends_at;
                $suggestions = $this->suggestions->suggest(
                    $category,
                    $allocationStart,
                    $allocationEnd,
                    capabilities: $requirement['capabilities'],
                    languages: $requirement['languages'],
                    propertyId: $reservation->property_id,
                )->take(3)->values()->all();
                $swap = $allocation?->resource_id === null ? null : Allocation::query()
                    ->where('id', '!=', $allocation->id)
                    ->where('status', '!=', AllocationStatus::Released)
                    ->whereNotNull('resource_id')
                    ->where('resource_id', '!=', $allocation->resource_id)
                    ->where('requested_category_id', $category->id)
                    ->where('starts_at', $allocation->starts_at)
                    ->where('ends_at', $allocation->ends_at)
                    ->whereHas('reservation', fn ($query) => $query->where('property_id', $reservation->property_id)
                        ->whereIn('status', [ReservationStatus::Hold, ReservationStatus::Confirmed, ReservationStatus::CheckedIn]))
                    ->first();

                return [
                    'reservation_id' => $reservation->id,
                    'reference' => $reservation->confirmation_number,
                    'category_id' => $category->id,
                    'category' => $category->name,
                    'category_slug' => $category->slug,
                    'kind' => $category->kind->value,
                    'required' => $required,
                    'assigned' => $assigned,
                    'conflicted' => $conflicted,
                    'allocation_id' => $allocation?->id,
                    'resource_id' => $allocation?->resource_id,
                    'reasons' => $ranked,
                    'suggestions' => $suggestions,
                    'swap' => $swap === null ? null : [
                        'allocation_id' => $swap->id,
                        'resource_id' => $swap->resource_id,
                    ],
                    'rank' => $conflicted ? 1 : ($assigned === 0 ? 2 : 3),
                ];
            })->filter()->values()->all();
        })->sortBy(fn (array $row): array => [$row['rank'], $row['category'], $row['reference']])->values();
    }

    private function isSharedOperationalCategory(ResourceCategory $category): bool
    {
        $label = mb_strtolower($category->slug.' '.$category->name);

        return preg_match('/\b(guide|horse|boat|vehicle)s?\b/', $label) === 1;
    }
}
