<?php

namespace App\Filament\Resources\CommissionAccruals;

use App\Filament\Resources\CommissionAccruals\Pages\CreateCommissionAccrual;
use App\Filament\Resources\CommissionAccruals\Pages\ListCommissionAccruals;
use App\Filament\Resources\CommissionAccruals\Pages\ViewCommissionAccrual;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\LodgeOpsPresentation;
use App\Models\CommissionAccrual;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CommissionAccrualResource extends TenantResource
{
    protected static ?string $model = CommissionAccrual::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 25;

    protected static ?string $recordTitleAttribute = 'payee_name';

    protected static ?string $viewCapability = 'canViewFinance';

    protected static string $writeCapability = 'canManageMoney';

    protected static ?string $propertyRelationship = 'reservation';

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Accrue commission')->description('The commission base is frozen from the selected reservation total.')->columns(2)->schema([
                Select::make('reservation_id')->label('Reservation')->options(LodgeOpsPresentation::reservationOptions(...))->required()->searchable()->preload()->columnSpanFull(),
                Select::make('payee_type')->options(['agency' => 'Agency', 'staff' => 'Staff', 'guide' => 'Guide', 'partner' => 'Partner'])->required(),
                TextInput::make('payee_name')->label('Payee')->required()->maxLength(160),
                TextInput::make('rate_basis_points')->label('Rate')->numeric()->minValue(0)->maxValue(10000)->suffix('basis points')->required(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Commission accrual')->columns(2)->schema([
                TextEntry::make('payee_name')->label('Payee')->weight('bold'),
                TextEntry::make('payee_type')->badge()->formatStateUsing(LodgeOpsPresentation::label(...)),
                TextEntry::make('reservation.confirmation_number')->label('Reservation'),
                TextEntry::make('rate_basis_points')->label('Rate')->formatStateUsing(fn (int $state): string => number_format($state / 100, 2).'%'),
                TextEntry::make('base_amount_minor')->label('Commission base')->money(fn (CommissionAccrual $record): string => $record->currency, divideBy: 100),
                TextEntry::make('amount_minor')->label('Commission')->money(fn (CommissionAccrual $record): string => $record->currency, divideBy: 100)->weight('bold'),
                TextEntry::make('status')->badge()->formatStateUsing(LodgeOpsPresentation::label(...))->color(fn ($state): string => LodgeOpsPresentation::statusColor($state)),
                TextEntry::make('paid_at')->label('Paid')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone())->placeholder('Outstanding'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('payee_name')->label('Payee')->searchable()->weight('medium'),
                TextColumn::make('payee_type')->badge()->formatStateUsing(LodgeOpsPresentation::label(...)),
                TextColumn::make('reservation.confirmation_number')->label('Reservation')->searchable(),
                TextColumn::make('amount_minor')->label('Commission')->money(fn (CommissionAccrual $record): string => $record->currency, divideBy: 100)->sortable(),
                TextColumn::make('status')->badge()->formatStateUsing(LodgeOpsPresentation::label(...))->color(fn ($state): string => LodgeOpsPresentation::statusColor($state)),
                TextColumn::make('paid_at')->label('Paid')->dateTime('M j, Y', timezone: LodgeOpsPresentation::timezone())->placeholder('—')->sortable(),
            ])
            ->filters([SelectFilter::make('status')->options(['accrued' => 'Accrued', 'paid' => 'Paid'])])
            ->recordActions([ViewAction::make(), self::markPaidAction()])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No commission accruals');
    }

    public static function markPaidAction(): Action
    {
        return Action::make('mark_paid')->label('Mark paid')->icon('heroicon-o-banknotes')->color('success')->requiresConfirmation()->visible(fn (CommissionAccrual $record): bool => $record->status !== 'paid' && self::canManageWorkflow($record))->action(function (CommissionAccrual $record): void {
            $record->update(['status' => 'paid', 'paid_at' => now()]);
            Notification::make()->success()->title('Commission marked paid')->send();
        });
    }

    public static function canManageWorkflow(CommissionAccrual $record): bool
    {
        return self::belongsToCurrentTenant($record) && self::canWrite();
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCommissionAccruals::route('/'),
            'create' => CreateCommissionAccrual::route('/create'),
            'view' => ViewCommissionAccrual::route('/{record}'),
        ];
    }
}
