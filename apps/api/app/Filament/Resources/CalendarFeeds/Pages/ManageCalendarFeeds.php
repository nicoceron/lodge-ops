<?php

namespace App\Filament\Resources\CalendarFeeds\Pages;

use App\Filament\Resources\CalendarFeeds\CalendarFeedResource;
use App\Services\CalendarFeedService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCalendarFeeds extends ManageRecords
{
    protected static string $resource = CalendarFeedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(fn (array $data) => app(CalendarFeedService::class)->create(
                $data['property_id'],
                $data['resource_id'],
                $data['name'],
            )),
        ];
    }
}
