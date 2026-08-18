<?php

namespace App\Filament\Resources\Resources\RelationManagers;

use App\Filament\Support\InnPresentation;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BlocksRelationManager extends RelationManager
{
    protected static string $relationship = 'blocks';

    protected static ?string $title = 'Availability blocks';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DateTimePicker::make('starts_at')->required()->seconds(false),
            DateTimePicker::make('ends_at')->required()->seconds(false)->after('starts_at'),
            TextInput::make('reason')->required()->maxLength(255),
            Textarea::make('notes')->rows(3)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('starts_at')->label('From')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->sortable(),
                TextColumn::make('ends_at')->label('Until')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->sortable(),
                TextColumn::make('reason')->searchable()->wrap(),
                TextColumn::make('notes')->limit(60)->placeholder('—'),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('starts_at');
    }
}
