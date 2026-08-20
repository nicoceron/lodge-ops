<?php

namespace App\Filament\Resources\Reservations\RelationManagers;

use App\Data\Payments\FrontDeskPaymentInput;
use App\Enums\DepositStatus;
use App\Enums\PaymentChannel;
use App\Filament\Support\InnPresentation;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\MoneyCalculator;
use App\Services\Payments\RecordFrontDeskPayment;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payments';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->headerActions([
            Action::make('record_front_desk_payment')
                ->label('Record front-desk payment')
                ->icon('heroicon-o-banknotes')
                ->authorize('create', Payment::class)
                ->schema([
                    Placeholder::make('authoritative_due')->label('Authoritative amount due')
                        ->content(fn (): string => $this->authoritativeDueLabel()),
                    Select::make('channel')->options([
                        'cash' => 'Cash',
                        'bank_transfer' => 'Bank transfer',
                        'external_terminal' => 'Standalone external terminal',
                        'manual_other' => 'Manual other',
                    ])->required()->live(),
                    TextInput::make('amount_minor')->label('Amount (minor units)')->integer()->minValue(1)->required(),
                    Select::make('deposit_id')->label('Apply to due deposit')->options(function (): array {
                        /** @var Reservation $reservation */
                        $reservation = $this->getOwnerRecord();

                        return Deposit::query()->where('reservation_id', $reservation->id)->where('status', DepositStatus::Due)->orderBy('due_at')->get()
                            ->mapWithKeys(fn (Deposit $deposit): array => [$deposit->id => $deposit->currency.' '.number_format($deposit->amount_minor / 100, 2).' · '.$deposit->due_at?->toDateString()])->all();
                    })->searchable(),
                    TextInput::make('processor_alias')->visible(fn ($get): bool => $get('channel') === 'external_terminal')->required(fn ($get): bool => $get('channel') === 'external_terminal')->maxLength(80),
                    TextInput::make('merchant_account_alias')->visible(fn ($get): bool => $get('channel') === 'external_terminal')->required(fn ($get): bool => $get('channel') === 'external_terminal')->maxLength(120),
                    TextInput::make('terminal_identifier')->visible(fn ($get): bool => $get('channel') === 'external_terminal')->required(fn ($get): bool => $get('channel') === 'external_terminal')->maxLength(80),
                    TextInput::make('transaction_reference')->label('Transaction / authorization reference')->visible(fn ($get): bool => in_array($get('channel'), ['external_terminal', 'bank_transfer'], true))->required(fn ($get): bool => $get('channel') === 'external_terminal')->maxLength(160)
                        ->helperText(fn ($get): ?string => $get('channel') === 'external_terminal'
                            ? 'Process the card on the standalone terminal first. Never enter the card number, expiry, CVV, track data, or PIN in Inn.'
                            : null),
                    TextInput::make('authorization_reference')->visible(fn ($get): bool => $get('channel') === 'external_terminal')->maxLength(160),
                    TextInput::make('batch_reference')->visible(fn ($get): bool => $get('channel') === 'external_terminal')->maxLength(120),
                    TextInput::make('card_brand')->visible(fn ($get): bool => $get('channel') === 'external_terminal')->maxLength(40),
                    TextInput::make('card_last_four')->visible(fn ($get): bool => $get('channel') === 'external_terminal')
                        ->length(4)->rule('regex:/^\d{4}$/')->inputMode('numeric'),
                    Textarea::make('note')->maxLength(500)->required(fn ($get): bool => $get('channel') === 'manual_other'),
                ])
                ->requiresConfirmation()
                ->modalDescription('Inn records an external tender after it occurred; Inn does not authorize or capture standalone-terminal cards.')
                ->action(function (array $data): void {
                    /** @var Reservation $reservation */
                    $reservation = $this->getOwnerRecord();
                    $detail = app(RecordFrontDeskPayment::class)->handle(auth()->user(), new FrontDeskPaymentInput(
                        reservationId: $reservation->id,
                        channel: PaymentChannel::from($data['channel']),
                        amountMinor: (int) $data['amount_minor'],
                        idempotencyKey: 'filament-front-desk:'.(string) str()->uuid(),
                        depositId: $data['deposit_id'] ?? null,
                        processorAlias: $data['processor_alias'] ?? null,
                        merchantAccountAlias: $data['merchant_account_alias'] ?? null,
                        terminalIdentifier: $data['terminal_identifier'] ?? null,
                        transactionReference: $data['transaction_reference'] ?? null,
                        authorizationReference: $data['authorization_reference'] ?? null,
                        batchReference: $data['batch_reference'] ?? null,
                        cardBrand: $data['card_brand'] ?? null,
                        cardLastFour: $data['card_last_four'] ?? null,
                        note: $data['note'] ?? null,
                    ));
                    $notification = Notification::make()->title(match ($detail->state) {
                        'posted' => 'Front-desk tender recorded truthfully',
                        'duplicate_review' => 'Possible duplicate held for Finance review',
                        default => 'Tender identity exception held for Finance review',
                    });
                    $detail->state === 'posted' ? $notification->success() : $notification->warning();
                    $notification->send();
                }),
        ])->columns([
            TextColumn::make('processed_at')->label('Processed')
                ->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->placeholder('Pending'),
            TextColumn::make('status')->badge()->formatStateUsing(fn ($state): string => InnPresentation::label($state))
                ->color(fn ($state): string => InnPresentation::statusColor($state)),
            TextColumn::make('amount_minor')->label('Amount')
                ->money(fn (Payment $record): string => $record->currency, divideBy: 100),
            TextColumn::make('channel')->formatStateUsing(fn ($state): string => InnPresentation::label($state)),
            TextColumn::make('origin')->badge()->formatStateUsing(fn ($state): string => InnPresentation::label($state)),
            TextColumn::make('tenderDetail.transaction_reference')->label('Reference')->placeholder('—')->copyable(),
        ])->defaultSort('processed_at', 'desc');
    }

    private function authoritativeDueLabel(): string
    {
        $reservation = $this->getOwnerRecord();
        if (! $reservation instanceof Reservation) {
            throw new \LogicException('Payments relation requires a reservation owner.');
        }

        return $reservation->currency.' '.number_format(max(0, app(MoneyCalculator::class)->reservationBalance($reservation)) / 100, 2);
    }
}
