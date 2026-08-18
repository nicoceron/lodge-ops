<?php

namespace App\Filament\Resources\Payments;

use App\Enums\DepositStatus;
use App\Enums\PaymentStatus;
use App\Filament\Support\InnPresentation;
use App\Models\Deposit;
use App\Models\Payment;
use App\Services\PaymentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

final class PaymentWorkflowActions
{
    /** @return array<Action> */
    public static function forRecord(): array
    {
        return [
            Action::make('reconcile')
                ->label('Reconcile')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->authorize('reconcile')
                ->schema([
                    Select::make('deposit_id')
                        ->label('Apply to deposit')
                        ->options(fn (Payment $record): array => Deposit::query()
                            ->where('reservation_id', $record->reservation_id)
                            ->where('status', DepositStatus::Due)
                            ->get()
                            ->mapWithKeys(fn (Deposit $deposit): array => [$deposit->id => "{$deposit->currency} ".number_format($deposit->amount_minor / 100, 2)." · due {$deposit->due_at?->toDateString()}"])
                            ->all())
                        ->searchable(),
                    TextInput::make('evidence_url')->url()->maxLength(2000),
                    Textarea::make('evidence_note')->rows(3)->maxLength(5000),
                ])
                ->requiresConfirmation()
                ->visible(fn (Payment $record): bool => PaymentResource::canRunWorkflow($record) && $record->status === PaymentStatus::Pending)
                ->action(function (Payment $record, array $data): void {
                    $record->update(array_filter([
                        'evidence_url' => $data['evidence_url'] ?? null,
                        'evidence_note' => $data['evidence_note'] ?? null,
                    ], fn ($value) => filled($value)));
                    app(PaymentService::class)->reconcile($record, auth()->id(), $data['deposit_id'] ?? null);
                    Notification::make()->success()->title('Payment reconciled and folio credit posted')->send();
                }),
            Action::make('reverse')
                ->label('Reverse payment')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->authorize('reverse')
                ->schema([
                    Textarea::make('reason')->required()->maxLength(5000)->rows(3),
                ])
                ->requiresConfirmation()
                ->visible(fn (Payment $record): bool => PaymentResource::canRunWorkflow($record) && $record->status === PaymentStatus::Succeeded)
                ->action(function (Payment $record, array $data): void {
                    app(PaymentService::class)->reverse($record, $data['reason'], auth()->id());
                    Notification::make()->success()->title('Payment reversed with balancing folio entry')->send();
                }),
        ];
    }

    public static function recordManual(): Action
    {
        return Action::make('record_manual_payment')
            ->label('Record manual payment')
            ->icon('heroicon-o-plus')
            ->authorize('create', Payment::class)
            ->visible(PaymentResource::canRecordManual(...))
            ->schema([
                Select::make('reservation_id')
                    ->options(InnPresentation::reservationOptions(...))
                    ->searchable()
                    ->required(),
                Select::make('method')
                    ->options(['bank_transfer' => 'Bank transfer', 'cash' => 'Cash', 'card' => 'Card', 'other' => 'Other'])
                    ->required(),
                TextInput::make('amount_minor')->label('Amount (minor units)')->integer()->minValue(1)->required(),
                TextInput::make('provider')->label('External processor')->maxLength(80)
                    ->helperText('Optional label only. This remains a staff-entered manual record, not a provider-captured payment.'),
                TextInput::make('provider_reference')->label('External reference')->maxLength(200),
                TextInput::make('evidence_url')->url()->maxLength(2000),
                Textarea::make('evidence_note')->rows(3)->maxLength(5000),
            ])
            ->action(function (array $data): void {
                app(PaymentService::class)->recordManual($data, auth()->id());
                Notification::make()->success()->title('Pending payment recorded for reconciliation')->send();
            });
    }
}
