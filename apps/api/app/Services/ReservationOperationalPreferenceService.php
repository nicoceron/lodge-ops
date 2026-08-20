<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationGuest;

final class ReservationOperationalPreferenceService
{
    /** @return list<string> */
    public function dietaryLabels(Reservation $reservation): array
    {
        $guests = collect([$reservation->primaryGuest])
            ->filter()
            ->concat($reservation->guests)
            ->unique('id')
            ->values();

        return $guests
            ->flatMap(function (Guest $guest): array {
                $pivot = $guest->relationLoaded('pivot') ? $guest->getRelation('pivot') : null;
                $pivotPreferences = $pivot instanceof ReservationGuest ? $pivot->operational_preferences : null;
                $guestPreferences = $this->preferenceArray($guest->getAttribute('preferences'));

                return [...$this->labels($guestPreferences), ...$this->labels($pivotPreferences)];
            })
            ->concat($reservation->guestPortalProfiles->flatMap(fn ($profile) => $this->labels($profile->preferences)))
            ->unique(fn (string $label): string => mb_strtolower($label))
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function restrictionLabels(Reservation $reservation): array
    {
        $guests = collect([$reservation->primaryGuest])->filter()->concat($reservation->guests)->unique('id')->values();
        $knownGuestIds = $guests->pluck('id');

        return $guests->flatMap(function (Guest $guest) use ($reservation): array {
            $pivot = $guest->relationLoaded('pivot') ? $guest->getRelation('pivot') : null;
            $pivotPreferences = $pivot instanceof ReservationGuest ? $pivot->operational_preferences : null;
            $guestPreferences = $this->preferenceArray($guest->getAttribute('preferences'));

            return collect([
                ...$this->labels($guestPreferences),
                ...$this->labels($pivotPreferences),
                ...$reservation->guestPortalProfiles->where('guest_id', $guest->id)->flatMap(fn ($profile) => $this->labels($profile->preferences)),
            ])->unique(fn (string $label): string => mb_strtolower($label))->values()->all();
        })->concat($reservation->guestPortalProfiles->whereNotIn('guest_id', $knownGuestIds)
            ->flatMap(fn ($profile) => $this->labels($profile->preferences)))
            ->values()->all();
    }

    /** @param array<string, mixed>|null $preferences @return list<string> */
    public function labels(?array $preferences): array
    {
        if ($preferences === null) {
            return [];
        }

        return collect([
            data_get($preferences, 'dietary'),
            data_get($preferences, 'dietary_style'),
            data_get($preferences, 'dietary_requirements'),
            data_get($preferences, 'allergies'),
            data_get($preferences, 'meal_notes'),
        ])->filter()->flatMap(function (mixed $value): array {
            if (is_array($value)) {
                return array_values(array_filter($value, 'is_string'));
            }

            return is_string($value) ? preg_split('/[,;]+/', $value) ?: [] : [];
        })->map(fn (string $value): string => trim($value))
            ->filter()
            ->unique(fn (string $value): string => mb_strtolower($value))
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function preferenceArray(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }
}
