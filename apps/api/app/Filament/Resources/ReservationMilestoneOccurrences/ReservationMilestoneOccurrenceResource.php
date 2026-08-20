<?php

namespace App\Filament\Resources\ReservationMilestoneOccurrences;

use App\Filament\Resources\ReservationMilestoneOccurrences\Pages\ManageReservationMilestoneOccurrences;
use App\Filament\Resources\TenantResource;
use App\Models\ReservationMilestoneOccurrence;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReservationMilestoneOccurrenceResource extends TenantResource
{
    protected static ?string $model = ReservationMilestoneOccurrence::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|\UnitEnum|null $navigationGroup = 'Templates & Integrations';

    protected static ?int $navigationSort = 28;

    protected static bool $canCreateRecords = false;

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('key')->badge(),
            TextEntry::make('state')->badge(),
            TextEntry::make('timezone'),
            TextEntry::make('target_local'),
            TextEntry::make('target_at')->dateTime(),
            TextEntry::make('rule_version'),
            TextEntry::make('policy_version'),
            TextEntry::make('supersession_reason')->placeholder('None'),
            TextEntry::make('last_error')->placeholder('None'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('target_at')->dateTime()->sortable(), TextColumn::make('key')->badge(),
            TextColumn::make('state')->badge()->sortable(), TextColumn::make('reservation.confirmation_number')->label('Reservation')->searchable(),
            TextColumn::make('attempts')->numeric(), TextColumn::make('last_error')->limit(36)->placeholder('—'),
        ])->filters([SelectFilter::make('state')->options([
            'pending' => 'Pending', 'claimed' => 'Claimed', 'dispatched' => 'Dispatched',
            'suppressed' => 'Suppressed', 'superseded' => 'Superseded',
        ])])->recordActions([ViewAction::make()])->defaultSort('target_at');
    }

    public static function getPages(): array
    {
        return ['index' => ManageReservationMilestoneOccurrences::route('/')];
    }
}
