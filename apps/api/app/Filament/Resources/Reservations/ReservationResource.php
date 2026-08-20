<?php

namespace App\Filament\Resources\Reservations;

use App\Enums\ReservationStatus;
use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Filament\Resources\Reservations\Pages\EditReservation;
use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Filament\Resources\Reservations\Pages\ViewReservation;
use App\Filament\Resources\Reservations\RelationManagers\AllocationsRelationManager;
use App\Filament\Resources\Reservations\RelationManagers\CommunicationsRelationManager;
use App\Filament\Resources\Reservations\RelationManagers\DepositsRelationManager;
use App\Filament\Resources\Reservations\RelationManagers\FolioLinesRelationManager;
use App\Filament\Resources\Reservations\RelationManagers\GeneratedDocumentsRelationManager;
use App\Filament\Resources\Reservations\RelationManagers\NotesRelationManager;
use App\Filament\Resources\Reservations\RelationManagers\OperationalTasksRelationManager;
use App\Filament\Resources\Reservations\RelationManagers\PaymentRequestsRelationManager;
use App\Filament\Resources\Reservations\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Reservations\RelationManagers\ReservationChangesRelationManager;
use App\Filament\Resources\Reservations\RelationManagers\StatusHistoryRelationManager;
use App\Filament\Resources\Reservations\Schemas\ReservationForm;
use App\Filament\Resources\Reservations\Schemas\ReservationInfolist;
use App\Filament\Resources\Reservations\Tables\ReservationsTable;
use App\Filament\Resources\TenantResource;
use App\Models\Reservation;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ReservationResource extends TenantResource
{
    protected static ?string $model = Reservation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $recordTitleAttribute = 'confirmation_number';

    protected static string|\UnitEnum|null $navigationGroup = 'Commercial';

    protected static ?int $navigationSort = 10;

    protected static bool $canDeleteRecords = false;

    protected static string $writeCapability = 'canManageReservations';

    protected static ?string $viewCapability = 'canManageReservations';

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()
            ->whereIn('status', [ReservationStatus::Draft, ReservationStatus::Hold])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Draft and held reservations awaiting confirmation';
    }

    public static function canEdit(Model $record): bool
    {
        return parent::canEdit($record)
            && $record instanceof Reservation
            && in_array($record->status, [ReservationStatus::Draft, ReservationStatus::Hold], true);
    }

    public static function canTransition(Reservation $record, ReservationStatus $status): bool
    {
        return static::belongsToCurrentTenant($record)
            && static::canWrite()
            && $record->status->canTransitionTo($status);
    }

    public static function form(Schema $schema): Schema
    {
        return ReservationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ReservationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReservationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AllocationsRelationManager::class,
            DepositsRelationManager::class,
            PaymentsRelationManager::class,
            PaymentRequestsRelationManager::class,
            FolioLinesRelationManager::class,
            OperationalTasksRelationManager::class,
            CommunicationsRelationManager::class,
            GeneratedDocumentsRelationManager::class,
            NotesRelationManager::class,
            ReservationChangesRelationManager::class,
            StatusHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReservations::route('/'),
            'create' => CreateReservation::route('/create'),
            'view' => ViewReservation::route('/{record}'),
            'edit' => EditReservation::route('/{record}/edit'),
        ];
    }
}
