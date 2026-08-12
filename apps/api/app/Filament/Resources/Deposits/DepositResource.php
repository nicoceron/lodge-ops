<?php

namespace App\Filament\Resources\Deposits;

use App\Enums\DepositStatus;
use App\Filament\Resources\Deposits\Pages\CreateDeposit;
use App\Filament\Resources\Deposits\Pages\EditDeposit;
use App\Filament\Resources\Deposits\Pages\ListDeposits;
use App\Filament\Resources\Deposits\Pages\ViewDeposit;
use App\Filament\Resources\Deposits\Schemas\DepositForm;
use App\Filament\Resources\Deposits\Schemas\DepositInfolist;
use App\Filament\Resources\Deposits\Tables\DepositsTable;
use App\Filament\Resources\TenantResource;
use App\Models\Deposit;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DepositResource extends TenantResource
{
    protected static ?string $model = Deposit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 20;

    protected static bool $canDeleteRecords = false;

    protected static ?string $viewCapability = 'canViewFinance';

    protected static string $writeCapability = 'canManageMoney';

    protected static ?string $propertyRelationship = 'reservation';

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()
            ->where('status', DepositStatus::Due)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Deposits due';
    }

    public static function canEdit(Model $record): bool
    {
        return parent::canEdit($record)
            && $record instanceof Deposit
            && $record->status === DepositStatus::Due;
    }

    public static function canWaive(Deposit $deposit): bool
    {
        return static::belongsToCurrentTenant($deposit)
            && static::canWrite()
            && $deposit->status === DepositStatus::Due;
    }

    public static function form(Schema $schema): Schema
    {
        return DepositForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DepositInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DepositsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeposits::route('/'),
            'create' => CreateDeposit::route('/create'),
            'view' => ViewDeposit::route('/{record}'),
            'edit' => EditDeposit::route('/{record}/edit'),
        ];
    }
}
