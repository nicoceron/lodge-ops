<?php

namespace App\Filament\Resources\Payments;

use App\Enums\PaymentStatus;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Payments\Schemas\PaymentForm;
use App\Filament\Resources\Payments\Schemas\PaymentInfolist;
use App\Filament\Resources\Payments\Tables\PaymentsTable;
use App\Filament\Resources\TenantResource;
use App\Models\Payment;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PaymentResource extends TenantResource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 10;

    protected static bool $canCreateRecords = false;

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    protected static ?string $viewCapability = 'canViewFinance';

    protected static string $writeCapability = 'canManageMoney';

    protected static ?string $propertyRelationship = 'reservation';

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()
            ->where('status', PaymentStatus::Pending)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Payments pending processing';
    }

    public static function canRunWorkflow(Payment $payment): bool
    {
        return static::belongsToCurrentTenant($payment) && static::canWrite();
    }

    public static function canRecordManual(): bool
    {
        return static::canWrite();
    }

    public static function form(Schema $schema): Schema
    {
        return PaymentForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PaymentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentsTable::configure($table);
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
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }
}
