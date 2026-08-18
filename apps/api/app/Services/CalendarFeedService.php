<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\ReservationStatus;
use App\Models\Allocation;
use App\Models\CalendarFeed;
use App\Models\ResourceBlock;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class CalendarFeedService
{
    public function create(string $propertyId, string $resourceId, string $name): CalendarFeed
    {
        $token = Str::random(64);

        return CalendarFeed::query()->create([
            'property_id' => $propertyId,
            'resource_id' => $resourceId,
            'name' => trim($name),
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'is_active' => true,
        ]);
    }

    public function url(CalendarFeed $feed): string
    {
        return route('calendar-feeds.show', ['token' => $feed->token]);
    }

    public function render(CalendarFeed $feed): string
    {
        $feed->loadMissing(['resource', 'property']);
        $allocations = Allocation::query()
            ->with('reservation:id,confirmation_number,status,hold_expires_at')
            ->where('resource_id', $feed->resource_id)
            ->where(function ($query): void {
                $query->where('status', AllocationStatus::Confirmed)
                    ->orWhere(function ($query): void {
                        $query->where('status', AllocationStatus::Tentative)
                            ->whereHas('reservation', fn ($reservation) => $reservation
                                ->where('status', ReservationStatus::Hold)
                                ->where('hold_expires_at', '>', now()));
                    });
            })
            ->where('ends_at', '>', now()->subYear())
            ->orderBy('starts_at')
            ->get();
        $blocks = ResourceBlock::query()
            ->where('resource_id', $feed->resource_id)
            ->where('ends_at', '>', now()->subYear())
            ->orderBy('starts_at')
            ->get();

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Inn//Channel Availability//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape($feed->name),
        ];

        foreach ($allocations as $allocation) {
            $lines = [...$lines, ...$this->event(
                "allocation-{$allocation->id}@inn",
                $allocation->starts_at,
                $allocation->ends_at,
                'Unavailable · '.$allocation->reservation->confirmation_number,
            )];
        }
        foreach ($blocks as $block) {
            $lines = [...$lines, ...$this->event(
                "block-{$block->id}@inn",
                $block->starts_at,
                $block->ends_at,
                'Unavailable · '.$block->reason,
            )];
        }

        return implode("\r\n", [...$lines, 'END:VCALENDAR', '']);
    }

    /** @return list<string> */
    private function event(string $uid, CarbonInterface $startsAt, CarbonInterface $endsAt, string $summary): array
    {
        return [
            'BEGIN:VEVENT',
            'UID:'.$uid,
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$startsAt->utc()->format('Ymd\THis\Z'),
            'DTEND:'.$endsAt->utc()->format('Ymd\THis\Z'),
            'SUMMARY:'.$this->escape($summary),
            'TRANSP:OPAQUE',
            'END:VEVENT',
        ];
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $value);
    }
}
