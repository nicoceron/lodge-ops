<?php

namespace App\Filament\Resources\PaymentAttempts;

use App\Enums\PaymentAttemptState;
use App\Filament\Resources\PaymentAttempts\Pages\ManagePaymentAttempts;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\PaymentAttempt;
use App\Services\Payments\CancelInPersonOrder;
use App\Services\Payments\ReconcileInPersonOrder;
use App\Services\Payments\ReconcileProviderPayment;
use BackedEnum;
use chillerlan\QRCode\QRCode;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentAttemptResource extends TenantResource
{
    protected static ?string $model = PaymentAttempt::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 12;

    protected static bool $canCreateRecords = false;

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    protected static ?string $viewCapability = 'canViewGuestMoney';

    protected static string $writeCapability = 'canManageMoney';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label('Started')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->sortable(),
            TextColumn::make('reservation.confirmation_number')->label('Reservation')->searchable(),
            TextColumn::make('state')->badge()
                ->state(fn (PaymentAttempt $record): string => InnPresentation::label($record->state))
                ->color(fn (PaymentAttempt $record): string => InnPresentation::statusColor($record->state)),
            TextColumn::make('channel')->badge()->formatStateUsing(fn (string $state): string => InnPresentation::label($state)),
            TextColumn::make('source_amount_minor')->label('Source')->money(fn (PaymentAttempt $record): string => $record->source_currency, divideBy: 100),
            TextColumn::make('charge_amount_minor')->label('Charged')->money(fn (PaymentAttempt $record): string => $record->charge_currency, divideBy: 100),
            TextColumn::make('provider_payment_id')->label('Provider payment')->copyable()->placeholder('—'),
            TextColumn::make('provider_order_id')->label('Order')->copyable()->placeholder('—'),
            TextColumn::make('paymentTerminal.display_name')->label('Point')->placeholder('—'),
            TextColumn::make('providerPosLocation.display_name')->label('QR POS')->placeholder('—'),
            TextColumn::make('provider_status_detail')->label('Detail')->placeholder('—'),
            TextColumn::make('last_error')->label('Exception')->limit(60)->placeholder('—'),
        ])->filters([SelectFilter::make('state')->options(InnPresentation::enumOptions(PaymentAttemptState::cases()))])
            ->recordActions([
                Action::make('display_qr')->label('Display QR')->icon('heroicon-o-qr-code')
                    ->visible(fn (PaymentAttempt $record): bool => $record->hasDisplayableQr())
                    ->modalHeading('Mercado Pago QR')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (PaymentAttempt $record) => $record->hasDisplayableQr() ? view('filament.payments.qr-order', [
                        'qrImage' => (new QRCode)->render((string) $record->qr_data_ciphertext),
                        'expiresAt' => $record->order_expires_at,
                        'amountMinor' => $record->charge_amount_minor,
                        'currency' => $record->charge_currency,
                    ]) : null),
                Action::make('reconcile')->icon('heroicon-o-arrow-path')->authorize('reconcile')
                    ->visible(fn (PaymentAttempt $record): bool => ($record->provider_payment_id !== null || $record->provider_order_id !== null) && $record->state !== PaymentAttemptState::Approved)
                    ->requiresConfirmation()->action(function (PaymentAttempt $record): void {
                        $record->provider_order_id !== null
                            ? app(ReconcileInPersonOrder::class)->handle($record)
                            : app(ReconcileProviderPayment::class)->handle($record);
                        Notification::make()->success()->title('Provider payment reconciled')->send();
                    }),
                Action::make('cancel_order')->label('Cancel order')->icon('heroicon-o-x-circle')->authorize('cancel')
                    ->visible(fn (PaymentAttempt $record): bool => $record->provider_order_id !== null && $record->state->reusable())
                    ->requiresConfirmation()->action(function (PaymentAttempt $record): void {
                        app(CancelInPersonOrder::class)->handle($record, 'filament-cancel-order:'.str()->uuid());
                        Notification::make()->success()->title('Cancellation reconciled')->send();
                    }),
            ])->defaultSort('created_at', 'desc');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->whereIn('state', ['mismatched', 'failed', 'pending', 'action_required'])->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getPages(): array
    {
        return ['index' => ManagePaymentAttempts::route('/')];
    }
}
