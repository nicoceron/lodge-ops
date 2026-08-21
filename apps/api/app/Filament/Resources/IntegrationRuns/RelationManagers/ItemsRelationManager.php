<?php

namespace App\Filament\Resources\IntegrationRuns\RelationManagers;

use App\Filament\Resources\IntegrationRuns\IntegrationRunResource;
use App\Filament\Support\InnPresentation;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ItemsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'items';

    protected static ?string $title = 'Run items';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return IntegrationRunResource::canView($ownerRecord);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Safe item inspection')->columns(2)->schema([
                TextEntry::make('external_key'), TextEntry::make('local_key')->placeholder('—'),
                TextEntry::make('status')->badge(), TextEntry::make('attempt'),
                TextEntry::make('available_at')->label('Available at')->dateTime('M j, Y · H:i:s', timezone: InnPresentation::timezone())->placeholder('Never'),
                TextEntry::make('started_at')->label('Started at')->dateTime('M j, Y · H:i:s', timezone: InnPresentation::timezone())->placeholder('Never'),
                TextEntry::make('finished_at')->label('Finished at')->dateTime('M j, Y · H:i:s', timezone: InnPresentation::timezone())->placeholder('Never'),
                TextEntry::make('idempotency_key')->copyable()->columnSpanFull(),
                TextEntry::make('payload_checksum')->copyable()->columnSpanFull(),
                TextEntry::make('request_checksum')->copyable()->placeholder('—')->columnSpanFull(),
                TextEntry::make('response_checksum')->copyable()->placeholder('—')->columnSpanFull(),
                TextEntry::make('last_error')->label('Safe error')->placeholder('No errors')->columnSpanFull(),
                TextEntry::make('deadLetter.id')->label('Dead letter')->copyable()->placeholder('None'),
                TextEntry::make('deadLetter.status')->label('Dead-letter state')->badge()->placeholder('None'),
                TextEntry::make('deadLetter.replay_count')->label('Replay count')->placeholder('0'),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('deadLetter'))->columns([
            TextColumn::make('page_number')->label('Page'), TextColumn::make('external_key'),
            TextColumn::make('local_key')->placeholder('—'), TextColumn::make('status')->badge(),
            TextColumn::make('attempt'),
            TextColumn::make('available_at')->label('Available at')->dateTime('M j, Y · H:i:s', timezone: InnPresentation::timezone())->placeholder('Never')->sortable(),
            TextColumn::make('started_at')->label('Started at')->dateTime('M j, Y · H:i:s', timezone: InnPresentation::timezone())->placeholder('Never')->sortable(),
            TextColumn::make('finished_at')->label('Finished at')->dateTime('M j, Y · H:i:s', timezone: InnPresentation::timezone())->placeholder('Never')->sortable(),
            TextColumn::make('payload_checksum')->label('Payload checksum')->limit(12)->copyable(),
            TextColumn::make('request_checksum')->label('Request checksum')->limit(12)->copyable()->placeholder('—'),
            TextColumn::make('response_checksum')->label('Response checksum')->limit(12)->copyable()->placeholder('—'),
            TextColumn::make('idempotency_key')->label('Idempotency')->limit(18)->copyable(),
            TextColumn::make('last_error')->label('Safe error')->limit(60)->placeholder('—'),
            TextColumn::make('deadLetter.id')->label('Dead-letter link')->copyable()->placeholder('None'),
            TextColumn::make('deadLetter.status')->label('Dead letter')->badge()->placeholder('None'),
            TextColumn::make('deadLetter.replay_count')->label('Replays')->placeholder('0'),
        ])->recordActions([ViewAction::make()])->defaultSort('created_at');
    }
}
