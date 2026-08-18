<?php

namespace App\Filament\Resources\Reservations\RelationManagers;

use App\Filament\Support\InnPresentation;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GeneratedDocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'generatedDocuments';

    protected static ?string $title = 'Documents';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label('Generated')
                ->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone()),
            TextColumn::make('kind')->badge()->formatStateUsing(InnPresentation::label(...)),
            TextColumn::make('status')->badge()->formatStateUsing(InnPresentation::label(...))
                ->color(fn ($state): string => InnPresentation::statusColor($state)),
            TextColumn::make('template.name')->label('Template')->placeholder('No template'),
            TextColumn::make('guest.full_name')->label('Guest')->placeholder('No guest'),
            TextColumn::make('signed_at')->label('Signed')
                ->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->placeholder('Not signed'),
        ])->defaultSort('created_at', 'desc');
    }
}
