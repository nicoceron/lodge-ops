<?php

namespace App\Filament\Resources\CashShifts;

use App\Enums\CashMovementType;
use App\Enums\CashShiftState;
use App\Filament\Resources\CashShifts\Pages\ManageCashShifts;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\CashShift;
use App\Models\Property;
use App\Services\Payments\ApproveCashVariance;
use App\Services\Payments\CloseCashShift;
use App\Services\Payments\OpenCashShift;
use App\Services\Payments\RecordCashMovement;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CashShiftResource extends TenantResource
{
    protected static ?string $model = CashShift::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 11;

    protected static bool $canCreateRecords = false;

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    protected static ?string $viewCapability = 'canViewGuestMoney';

    protected static string $writeCapability = 'canManageGuestMoney';

    protected static ?string $propertyRelationship = 'property';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('business_date')->date(),
            TextColumn::make('cashier.name'),
            TextColumn::make('state')->badge(),
            TextColumn::make('currency'),
            TextColumn::make('current_expected_minor')->label('Current expected')
                ->state(fn (CashShift $record): int => $record->state === CashShiftState::Open ? $record->currentExpectedMinor() : (int) $record->expected_cash_minor)
                ->money(fn (CashShift $record): string => $record->currency, divideBy: 100),
            TextColumn::make('expected_cash_minor')->money(fn (CashShift $record): string => $record->currency, divideBy: 100)->placeholder('Open'),
            TextColumn::make('counted_cash_minor')->money(fn (CashShift $record): string => $record->currency, divideBy: 100)->placeholder('Open'),
            TextColumn::make('variance_minor')->money(fn (CashShift $record): string => $record->currency, divideBy: 100)->placeholder('—'),
            TextColumn::make('opened_at')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone()),
        ])->headerActions([
            Action::make('open_shift')->label('Open cash shift')->authorize('create', CashShift::class)->schema([
                Select::make('property_id')->options(Property::query()->pluck('name', 'id'))->required(),
                TextInput::make('currency')->default('USD')->length(3)->required(),
                TextInput::make('opening_float_minor')->integer()->minValue(0)->required(),
            ])->action(function (array $data): void {
                app(OpenCashShift::class)->handle(auth()->user(), $data['property_id'], $data['currency'], (int) $data['opening_float_minor'], 'filament-open-shift:'.str()->uuid());
                Notification::make()->success()->title('Cash shift opened')->send();
            }),
        ])->recordActions([
            Action::make('pay_in')->schema([TextInput::make('amount_minor')->integer()->minValue(1)->required(), Textarea::make('reason')->required()->maxLength(500)])
                ->authorize('operate')
                ->visible(fn (CashShift $record): bool => $record->state === CashShiftState::Open && $record->cashier_id === auth()->id())
                ->action(fn (CashShift $record, array $data) => app(RecordCashMovement::class)->handle(auth()->user(), $record, CashMovementType::PayIn, (int) $data['amount_minor'], $data['reason'], 'filament-pay-in:'.str()->uuid())),
            Action::make('pay_out')->schema([TextInput::make('amount_minor')->integer()->minValue(1)->required(), Textarea::make('reason')->required()->maxLength(500)])
                ->authorize('operate')
                ->visible(fn (CashShift $record): bool => $record->state === CashShiftState::Open && $record->cashier_id === auth()->id())
                ->action(fn (CashShift $record, array $data) => app(RecordCashMovement::class)->handle(auth()->user(), $record, CashMovementType::PayOut, (int) $data['amount_minor'], $data['reason'], 'filament-pay-out:'.str()->uuid())),
            Action::make('correct')->label('Opposing correction')->authorize('operate')->schema([
                Select::make('movement_id')->options(fn (CashShift $record): array => $record->movements()
                    ->where('type', '!=', CashMovementType::Correction->value)
                    ->whereDoesntHave('correction')->get()
                    ->mapWithKeys(fn ($movement): array => [$movement->id => $movement->type->value.' · '.$movement->amount_minor.' '.$movement->currency])->all())->required(),
                Textarea::make('reason')->required()->maxLength(500),
            ])->visible(fn (CashShift $record): bool => $record->state === CashShiftState::Open && $record->cashier_id === auth()->id())
                ->requiresConfirmation()->action(function (CashShift $record, array $data): void {
                    $movement = $record->movements()->findOrFail($data['movement_id']);
                    app(RecordCashMovement::class)->handle(auth()->user(), $record, CashMovementType::Correction, abs($movement->amount_minor), $data['reason'], 'filament-correction:'.str()->uuid(), $movement);
                }),
            Action::make('close')->authorize('operate')->schema([TextInput::make('counted_cash_minor')->integer()->minValue(0)->required(), Textarea::make('reason')->maxLength(500), Toggle::make('force')->helperText('Managers may force-close another cashier only when the shift is stale or the cashier is disabled.')])
                ->visible(fn (CashShift $record): bool => $record->state === CashShiftState::Open)
                ->requiresConfirmation()->action(fn (CashShift $record, array $data) => app(CloseCashShift::class)->handle(auth()->user(), $record, (int) $data['counted_cash_minor'], $data['reason'] ?? null, 'filament-close-shift:'.str()->uuid(), (bool) ($data['force'] ?? false))),
            Action::make('approve_variance')->schema([Textarea::make('reason')->required()->maxLength(500)])
                ->authorize('approveVariance')->visible(fn (CashShift $record): bool => $record->state === CashShiftState::VarianceReview)
                ->requiresConfirmation()->action(fn (CashShift $record, array $data) => app(ApproveCashVariance::class)->handle(auth()->user(), $record, $data['reason'], 'filament-approve-variance:'.str()->uuid())),
            Action::make('shift_report')->label('Shift report')->icon('heroicon-o-arrow-down-tray')->authorize('view')
                ->url(fn (CashShift $record): string => route('filament.admin.cash-shifts.report', ['tenant' => filament()->getTenant(), 'cashShift' => $record]))->openUrlInNewTab(),
        ])->defaultSort('opened_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageCashShifts::route('/')];
    }
}
