<?php

namespace App\Filament\Resources\Reservations;

use App\Enums\DepositStatus;
use App\Enums\PaymentRequestPurpose;
use App\Models\Deposit;
use App\Models\PaymentRequest;
use App\Models\Reservation;
use App\Services\Payments\IssuePaymentRequest;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;

final class PaymentRequestActions
{
    public static function issue(): Action
    {
        return Action::make('issuePaymentRequest')
            ->label('Issue payment link')
            ->icon('heroicon-o-link')
            ->color('success')
            ->visible(fn (): bool => Gate::allows('create', PaymentRequest::class))
            ->schema([
                Select::make('purpose')->options([
                    'deposit' => 'Due deposit',
                    'balance' => 'Outstanding balance',
                    'full_outstanding' => 'Full outstanding',
                    'authorized_partial' => 'Authorized partial amount',
                ])->required()->live(),
                Select::make('deposit_id')->label('Due deposit')
                    ->options(fn (Reservation $record): array => $record->deposits()->where('status', DepositStatus::Due)->get()
                        ->mapWithKeys(fn (Deposit $deposit): array => [$deposit->id => "{$deposit->currency} ".number_format($deposit->amount_minor / 100, 2)])->all()),
                TextInput::make('amount_minor')->label('Authorized partial · minor units')->integer()->minValue(1),
                DateTimePicker::make('expires_at')->label('Expires')->default(fn () => now()->addDays(3))->required()->after('now'),
            ])
            ->modalDescription('The amount is recalculated from the locked reservation. The guest receives an Inn-owned link; no card data is collected here.')
            ->action(function (Reservation $record, array $data): void {
                $issued = app(IssuePaymentRequest::class)->handle(
                    $record,
                    PaymentRequestPurpose::from($data['purpose']),
                    $data['deposit_id'] ?? null,
                    isset($data['amount_minor']) ? (int) $data['amount_minor'] : null,
                    auth()->id(),
                    $data['expires_at'],
                );
                Notification::make()->success()->title('Secure payment link issued')
                    ->body(url('/pay/'.$issued->token).' · Copy this link now; Inn stores only its hash.')
                    ->persistent()->send();
            });
    }
}
