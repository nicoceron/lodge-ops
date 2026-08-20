<?php

namespace App\Filament\Resources\SettlementEntries;

use App\Enums\SettlementReconciliationState;
use App\Filament\Resources\SettlementEntries\Pages\ManageSettlementEntries;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\SettlementEntry;
use App\Services\Payments\ResolveSettlementVariance;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SettlementEntryResource extends TenantResource
{
    protected static ?string $model = SettlementEntry::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 14;

    protected static bool $canCreateRecords = false;

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    protected static ?string $viewCapability = 'canViewFinance';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label('Recorded')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->sortable(),
            TextColumn::make('source_type')->badge()->formatStateUsing(InnPresentation::label(...)),
            TextColumn::make('source_id')->label('Provider source')->copyable(),
            TextColumn::make('gross_minor')->label('Gross')->money(fn (SettlementEntry $record): string => $record->currency, divideBy: 100),
            TextColumn::make('fee_minor')->label('Fees')->money(fn (SettlementEntry $record): string => $record->currency, divideBy: 100),
            TextColumn::make('tax_minor')->label('Tax')->money(fn (SettlementEntry $record): string => $record->currency, divideBy: 100)->placeholder('Not returned'),
            TextColumn::make('withholding_minor')->label('Withholding')->money(fn (SettlementEntry $record): string => $record->currency, divideBy: 100)->placeholder('Not returned'),
            TextColumn::make('net_minor')->label('Net')->money(fn (SettlementEntry $record): string => $record->currency, divideBy: 100),
            TextColumn::make('reconciliation_state')->label('Reconciliation')->badge()
                ->state(fn (SettlementEntry $record): string => InnPresentation::label($record->reconciliation_state))
                ->color(fn (SettlementEntry $record): string => InnPresentation::statusColor($record->reconciliation_state)),
        ])->filters([SelectFilter::make('reconciliation_state')->options(InnPresentation::enumOptions(SettlementReconciliationState::cases()))])
            ->recordActions([
                Action::make('investigate')->authorize('resolve')->visible(fn (SettlementEntry $record): bool => $record->reconciliation_state === SettlementReconciliationState::Variance)
                    ->schema([Textarea::make('notes')->required()->maxLength(2000)])
                    ->action(function (SettlementEntry $record, array $data): void {
                        app(ResolveSettlementVariance::class)->handle($record, 'investigate', $data['notes'], auth()->id());
                        Notification::make()->success()->title('Variance investigation recorded')->send();
                    }),
                Action::make('resolve')->authorize('resolve')->visible(fn (SettlementEntry $record): bool => $record->reconciliation_state === SettlementReconciliationState::Variance)
                    ->requiresConfirmation()->schema([Textarea::make('notes')->required()->maxLength(2000)])
                    ->action(function (SettlementEntry $record, array $data): void {
                        app(ResolveSettlementVariance::class)->handle($record, 'resolve', $data['notes'], auth()->id());
                        Notification::make()->success()->title('Variance resolved with immutable notes')->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageSettlementEntries::route('/')];
    }
}
