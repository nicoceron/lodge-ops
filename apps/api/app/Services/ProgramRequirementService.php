<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\ResourceType;
use App\Models\ProgramResourceRequirement;
use App\Models\Reservation;
use App\Models\Resource;
use Illuminate\Validation\ValidationException;

class ProgramRequirementService
{
    public function assertSatisfied(Reservation $reservation): void
    {
        $reservation->loadMissing(['program.requirements', 'allocations.resource']);
        if ($reservation->program_id !== null && ($reservation->program === null || $reservation->program->property_id !== $reservation->property_id || ! $reservation->program->is_active)) {
            throw ValidationException::withMessages([
                'program_id' => 'The selected program must be active and belong to the reservation property.',
            ]);
        }

        $allocations = $reservation->allocations
            ->where('status', '!==', AllocationStatus::Released)
            ->filter(fn ($allocation): bool => $allocation->resource !== null);

        $requiresAccommodation = $reservation->program_id === null
            || data_get($reservation, 'program.requires_accommodation') === true;
        if ($requiresAccommodation && ! $allocations->contains(
            fn ($allocation): bool => $allocation->resource->type === ResourceType::Room
                && $allocation->starts_at <= $reservation->starts_at
                && $allocation->ends_at >= $reservation->ends_at,
        )) {
            throw ValidationException::withMessages([
                'allocations' => 'This program requires a room allocation covering the full stay.',
            ]);
        }

        $partySize = max(1, $reservation->adults + $reservation->children);
        foreach ($reservation->program?->requirements ?? [] as $requirement) {
            $required = $requirement->quantityForParty($partySize);
            $assigned = $allocations
                ->filter(fn ($allocation): bool => $this->matches($allocation->resource, $requirement))
                ->sum('quantity');

            if ($assigned < $required) {
                throw ValidationException::withMessages([
                    'allocations' => "Program requirement not met: {$required} {$requirement->resource_type->value} resource(s) required; {$assigned} assigned.",
                ]);
            }
        }
    }

    private function matches(Resource $resource, ProgramResourceRequirement $requirement): bool
    {
        if ($resource->type !== $requirement->resource_type || ! $resource->is_active) {
            return false;
        }

        $attributes = $resource->attributes ?? [];
        $capabilities = $this->values([
            ...$this->values($attributes['capabilities'] ?? []),
            ...$this->values($attributes['specialties'] ?? []),
        ]);
        $languages = $this->values($attributes['languages'] ?? []);

        return collect($this->values($requirement->capabilities ?? []))->every(fn (string $value): bool => in_array($value, $capabilities, true))
            && collect($this->values($requirement->languages ?? []))->every(fn (string $value): bool => in_array($value, $languages, true));
    }

    /** @return array<int, string> */
    private function values(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($item): string => mb_strtolower(trim((string) $item)),
            $value,
        ))));
    }
}
