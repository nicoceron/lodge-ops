<?php

namespace App\Filament\Resources\Opportunities\RelationManagers;

use App\Filament\Support\InnPresentation;
use App\Models\CrmActivity;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')->options(['note' => 'Note', 'call' => 'Call', 'email' => 'Email', 'meeting' => 'Meeting', 'task' => 'Task'])->required(),
            TextInput::make('subject')->required()->maxLength(255),
            Textarea::make('body')->rows(4)->columnSpanFull(),
            DateTimePicker::make('due_at')->label('Due')->seconds(false),
            DateTimePicker::make('completed_at')->label('Completed')->seconds(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')->badge()->formatStateUsing(InnPresentation::label(...)),
                TextColumn::make('subject')->searchable()->weight('medium')->wrap(),
                TextColumn::make('due_at')->label('Due')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->placeholder('—')->sortable(),
                TextColumn::make('completed_at')->label('Completed')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->placeholder('Open'),
                TextColumn::make('actor.name')->label('Owner')->placeholder('—'),
            ])
            ->filters([TernaryFilter::make('completed')->queries(true: fn ($query) => $query->whereNotNull('completed_at'), false: fn ($query) => $query->whereNull('completed_at'))])
            ->headerActions([
                CreateAction::make()->mutateDataUsing(fn (array $data): array => [...$data, 'actor_id' => auth()->id()]),
            ])
            ->recordActions([
                Action::make('complete')->icon('heroicon-o-check')->color('success')->authorize('update')->visible(fn (CrmActivity $record): bool => $record->completed_at === null)->action(fn (CrmActivity $record) => $record->update(['completed_at' => now()])),
                EditAction::make(),
            ])
            ->defaultSort('due_at');
    }
}
