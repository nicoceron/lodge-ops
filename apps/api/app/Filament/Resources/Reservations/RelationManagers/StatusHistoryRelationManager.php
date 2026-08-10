<?php

namespace App\Filament\Resources\Reservations\RelationManagers;

use App\Filament\Support\LodgeOpsPresentation;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StatusHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'statusHistory';

    protected static ?string $title = 'Status history';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('changed_at')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone())->sortable(),
            TextColumn::make('from_status')->badge()->placeholder('Created')->formatStateUsing(LodgeOpsPresentation::label(...)),
            TextColumn::make('to_status')->badge()->formatStateUsing(LodgeOpsPresentation::label(...)),
            TextColumn::make('actor.name')->placeholder('System'),
        ])->defaultSort('changed_at', 'desc');
    }
}
