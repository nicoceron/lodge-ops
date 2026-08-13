<?php

namespace App\Filament\Resources\Reservations\RelationManagers;

use App\Filament\Support\LodgeOpsPresentation;
use App\Models\ReservationNote;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NotesRelationManager extends RelationManager
{
    protected static string $relationship = 'noteTimeline';

    protected static ?string $title = 'Note timeline';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('kind')->options(ReservationNote::KINDS)->default('internal')->required(),
            DateTimePicker::make('occurred_at')->label('Occurred at')->default(now())->seconds(false)->required(),
            Textarea::make('body')->rows(4)->maxLength(10000)->required()->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone())->sortable(),
                TextColumn::make('kind')->badge()->formatStateUsing(fn (string $state): string => ReservationNote::KINDS[$state] ?? LodgeOpsPresentation::label($state)),
                TextColumn::make('body')->wrap()->searchable(),
                TextColumn::make('creator.name')->label('Added by')->placeholder('System'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add note')
                    ->mutateDataUsing(fn (array $data): array => [...$data, 'created_by' => auth()->id()]),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->emptyStateHeading('No timeline notes')
            ->emptyStateDescription('Add guest requests, operational handoffs, and internal decisions without overwriting history.');
    }
}
