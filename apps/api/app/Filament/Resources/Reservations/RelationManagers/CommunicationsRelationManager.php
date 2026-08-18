<?php

namespace App\Filament\Resources\Reservations\RelationManagers;

use App\Filament\Support\InnPresentation;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CommunicationsRelationManager extends RelationManager
{
    protected static string $relationship = 'communications';

    protected static ?string $title = 'Communications';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label('Created')
                ->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone()),
            TextColumn::make('channel')->badge()->formatStateUsing(InnPresentation::label(...)),
            TextColumn::make('direction')->badge()->formatStateUsing(InnPresentation::label(...)),
            TextColumn::make('status')->badge()->formatStateUsing(InnPresentation::label(...))
                ->color(fn ($state): string => InnPresentation::statusColor($state)),
            TextColumn::make('subject')->placeholder('No subject')->wrap(),
            TextColumn::make('guest.full_name')->label('Guest')->placeholder('No guest'),
        ])->defaultSort('created_at', 'desc');
    }
}
