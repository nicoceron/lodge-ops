<?php

namespace App\Filament\Resources\Payments;

use App\Enums\DepositStatus;
use App\Enums\DocumentKind;
use App\Enums\PaymentOrigin;
use App\Enums\PaymentStatus;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\User;
use App\Services\Documents\RequestDocumentGeneration;
use App\Services\Payments\CorrectRemainingReversibleAmount;
use App\Services\Payments\RequestManualExternalRefund;
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
            Action::make('generate_payment_receipt')
                ->label('Generate receipt')->icon('heroicon-o-document-arrow-down')
                ->visible(fn (Payment $record): bool => in_array($record->status, [PaymentStatus::Succeeded, PaymentStatus::Refunded], true))
                ->action(function (Payment $record): void {
                    app(RequestDocumentGeneration::class)->handle(User::query()->findOrFail(auth()->id()), $record->reservation, DocumentKind::PaymentReceipt, app()->getLocale(), (string) str()->uuid(), $record);
                    Notification::make()->success()->title('Payment receipt queued')->send();
                }),
            Action::make('request_manual_refund')
                ->label('Request manual refund')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->authorize('reverse')
                ->visible(fn (Payment $record): bool => PaymentResource::canRunWorkflow($record)
                    && $record->origin === PaymentOrigin::Manual
                    && $record->status === PaymentStatus::Succeeded)
                ->schema([
                    TextInput::make('amount_minor')->label('Amount (minor units)')->integer()->minValue(1)->required(),
                    Textarea::make('reason')->required()->maxLength(500)->rows(3),
                ])
                ->requiresConfirmation()
                ->modalDescription('This records a controlled refund request only. Execute the refund outside Inn, then attach and approve private evidence before completion.')
                ->action(function (Payment $record, array $data): void {
                    app(RequestManualExternalRefund::class)->handle(
                        auth()->user(),
                        $record,
                        (int) $data['amount_minor'],
                        $data['reason'],
                        'filament-manual-refund-request:'.str()->uuid(),
                    );
                    Notification::make()->success()->title('Manual refund requested; no refund is completed yet')->send();
                }),
            Action::make('request_remaining_correction')
                ->label('Request remaining reversible amount')
                ->icon('heroicon-o-calculator')
                ->authorize('reverse')
                ->visible(fn (Payment $record): bool => PaymentResource::canRunWorkflow($record)
                    && $record->origin === PaymentOrigin::Manual
                    && $record->status === PaymentStatus::Succeeded)
                ->schema([Textarea::make('reason')->required()->maxLength(500)->rows(3)])
                ->requiresConfirmation()
                ->modalDescription('Inn derives the remaining reversible amount and creates a request. It does not fabricate external execution.')
                ->action(function (Payment $record, array $data): void {
                    app(CorrectRemainingReversibleAmount::class)->handle(
                        auth()->user(),
                        $record,
                        $data['reason'],
                        'filament-remaining-refund-request:'.str()->uuid(),
                    );
                    Notification::make()->success()->title('Remaining reversible amount requested')->send();
                }),
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
        ];
    }
}
