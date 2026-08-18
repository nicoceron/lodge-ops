<?php

namespace App\Filament\Resources\Reservations\RelationManagers;

use App\Filament\Support\InnPresentation;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OperationalTasksRelationManager extends RelationManager
{
    protected static string $relationship = 'operationalTasks';

    protected static ?string $title = 'Tasks';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable()->wrap(),
            TextColumn::make('status')->badge()->formatStateUsing(InnPresentation::label(...))
                ->color(fn ($state): string => InnPresentation::statusColor($state)),
            TextColumn::make('priority')->badge()->formatStateUsing(InnPresentation::label(...)),
            TextColumn::make('assignee.name')->label('Owner')->placeholder('Unassigned'),
            TextColumn::make('due_at')->label('Due')
                ->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->placeholder('No deadline'),
        ])->defaultSort('due_at');
    }
}
