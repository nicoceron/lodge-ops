<?php

namespace App\Filament\Resources\PaymentAttempts;

use App\Enums\PaymentAttemptState;
use App\Filament\Resources\PaymentAttempts\Pages\ManagePaymentAttempts;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\PaymentAttempt;
use App\Services\Payments\ReconcileProviderPayment;
use BackedEnum;
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

    protected static ?string $viewCapability = 'canViewFinance';

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
            TextColumn::make('state')->badge()->formatStateUsing(InnPresentation::label(...))->color(fn ($state): string => InnPresentation::statusColor($state)),
            TextColumn::make('source_amount_minor')->label('Source')->money(fn (PaymentAttempt $record): string => $record->source_currency, divideBy: 100),
            TextColumn::make('charge_amount_minor')->label('Charged')->money(fn (PaymentAttempt $record): string => $record->charge_currency, divideBy: 100),
            TextColumn::make('provider_payment_id')->label('Provider payment')->copyable()->placeholder('—'),
            TextColumn::make('provider_status_detail')->label('Detail')->placeholder('—'),
            TextColumn::make('last_error')->label('Exception')->limit(60)->placeholder('—'),
        ])->filters([SelectFilter::make('state')->options(InnPresentation::enumOptions(PaymentAttemptState::cases()))])
            ->recordActions([
                Action::make('reconcile')->icon('heroicon-o-arrow-path')->authorize('reconcile')
                    ->visible(fn (PaymentAttempt $record): bool => $record->provider_payment_id !== null && $record->state !== PaymentAttemptState::Approved)
                    ->requiresConfirmation()->action(function (PaymentAttempt $record): void {
                        app(ReconcileProviderPayment::class)->handle($record);
                        Notification::make()->success()->title('Provider payment reconciled')->send();
                    }),
            ])->defaultSort('created_at', 'desc');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->whereIn('state', ['mismatched', 'failed', 'pending'])->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getPages(): array
    {
        return ['index' => ManagePaymentAttempts::route('/')];
    }
}
