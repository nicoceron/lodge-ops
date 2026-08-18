<?php

namespace App\Filament\Resources\OperationalTasks\Schemas;

use App\Filament\Support\InnPresentation;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OperationalTaskInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Work item')->columns(2)->schema([
                TextEntry::make('title')->columnSpanFull(),
                TextEntry::make('status')->badge()->formatStateUsing(InnPresentation::label(...))->color(fn ($state): string => InnPresentation::statusColor($state)),
                TextEntry::make('priority')->badge()->formatStateUsing(InnPresentation::label(...))->color(fn (?string $state): string => InnPresentation::priorityColor($state)),
                TextEntry::make('property.name')->label('Property'),
                TextEntry::make('assignee.name')->label('Owner')->placeholder('Unassigned'),
                TextEntry::make('reservation.confirmation_number')->label('Reservation')->placeholder('General task'),
                TextEntry::make('due_at')->label('Due')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->placeholder('No deadline'),
                TextEntry::make('description')->placeholder('No description')->columnSpanFull(),
            ]),
        ]);
    }
}
