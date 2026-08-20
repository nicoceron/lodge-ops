<?php

namespace App\Filament\Resources\ProviderRefunds;

use App\Filament\Resources\ProviderRefunds\Pages\ManageProviderRefunds;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\ProviderRefund;
use App\Services\Payments\RecoverProviderRefund;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProviderRefundResource extends TenantResource
{
    protected static ?string $model = ProviderRefund::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 13;

    protected static bool $canCreateRecords = false;

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    protected static ?string $viewCapability = 'canViewFinance';

    protected static string $writeCapability = 'canManageMoney';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->sortable(),
            TextColumn::make('payment.reservation.confirmation_number')->label('Reservation')->searchable(),
            TextColumn::make('state')->badge()
                ->state(fn (ProviderRefund $record): string => InnPresentation::label($record->state))
                ->color(fn (ProviderRefund $record): string => InnPresentation::statusColor($record->state)),
            TextColumn::make('source_amount_minor')->money(fn (ProviderRefund $record): string => $record->source_currency, divideBy: 100),
            TextColumn::make('provider_payment_id')->label('Provider payment')->copyable(),
            TextColumn::make('provider_refund_id')->copyable()->placeholder('Awaiting recovery ID'),
            TextColumn::make('last_error')->limit(70)->placeholder('—'),
        ])->recordActions([
            Action::make('recover')->authorize('recover')->requiresConfirmation()->schema([
                TextInput::make('provider_refund_id')->required()->maxLength(160),
            ])->action(function (ProviderRefund $record, array $data): void {
                app(RecoverProviderRefund::class)->handle($record, $data['provider_refund_id'], auth()->id());
                Notification::make()->success()->title('Provider refund recovered authoritatively')->send();
            }),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageProviderRefunds::route('/')];
    }
}
