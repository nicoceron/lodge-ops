<?php

namespace App\Filament\Resources\DocumentTemplates\RelationManagers;

use App\Filament\Support\InnPresentation;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Immutable generated document')->columns(2)->schema([
                TextEntry::make('kind')->badge(),
                TextEntry::make('status')->badge()->formatStateUsing(InnPresentation::label(...)),
                TextEntry::make('reservation.confirmation_number')->label('Reservation')->placeholder('—'),
                TextEntry::make('guest.email')->label('Guest')->placeholder('—'),
                TextEntry::make('checksum')->copyable()->columnSpanFull(),
                KeyValueEntry::make('metadata')->columnSpanFull()->placeholder('No metadata'),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Generated')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->sortable(),
                TextColumn::make('kind')->badge(),
                TextColumn::make('reservation.confirmation_number')->label('Reservation')->placeholder('—'),
                TextColumn::make('guest.email')->label('Guest')->placeholder('—'),
                TextColumn::make('checksum')->limit(12)->copyable(),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('created_at', 'desc');
    }
}
