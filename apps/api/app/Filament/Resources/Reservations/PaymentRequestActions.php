<?php

namespace App\Filament\Resources\Reservations;

use App\Enums\DepositStatus;
use App\Enums\PaymentChannel;
use App\Enums\PaymentRequestPurpose;
use App\Models\Deposit;
use App\Models\PaymentRequest;
use App\Models\PaymentTerminal;
use App\Models\ProviderPosLocation;
use App\Models\Reservation;
use App\Services\Payments\InitiateInPersonPayment;
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

    public static function point(): Action
    {
        return self::inPerson('chargePoint', 'Charge on Point', 'heroicon-o-device-phone-mobile', PaymentChannel::IntegratedTerminal);
    }

    public static function qr(): Action
    {
        return self::inPerson('showQr', 'Show QR', 'heroicon-o-qr-code', PaymentChannel::Qr);
    }

    private static function inPerson(string $name, string $label, string $icon, PaymentChannel $channel): Action
    {
        $targetField = $channel === PaymentChannel::IntegratedTerminal ? 'target_id' : 'target_id';

        return Action::make($name)->label($label)->icon($icon)->color('success')
            ->visible(fn (): bool => Gate::allows('create', PaymentRequest::class))
            ->schema([
                Select::make($targetField)->label($channel === PaymentChannel::IntegratedTerminal ? 'Point terminal' : 'QR POS')
                    ->options(fn (Reservation $record): array => ($channel === PaymentChannel::IntegratedTerminal
                        ? PaymentTerminal::query()->where('property_id', $record->property_id)->where('is_enabled', true)->where('operating_mode', 'PDV')->get()
                        : ProviderPosLocation::query()->where('property_id', $record->property_id)->where('is_enabled', true)->get())
                        ->mapWithKeys(fn ($target): array => [$target->id => $target->display_name])->all())->required(),
                Select::make('purpose')->options([
                    'deposit' => 'Due deposit', 'balance' => 'Outstanding balance',
                    'full_outstanding' => 'Full outstanding', 'authorized_partial' => 'Authorized partial amount',
                ])->required()->live(),
                Select::make('deposit_id')->label('Due deposit')->options(fn (Reservation $record): array => $record->deposits()
                    ->where('status', DepositStatus::Due)->get()->mapWithKeys(fn (Deposit $deposit): array => [
                        $deposit->id => "{$deposit->currency} ".number_format($deposit->amount_minor / 100, 2),
                    ])->all()),
                TextInput::make('amount_minor')->label('Authorized partial · minor units')->integer()->minValue(1),
            ])
            ->modalDescription($channel === PaymentChannel::IntegratedTerminal
                ? 'Inn sends the locked amount to the selected PDV terminal. The device screen never posts money; authoritative Orders lookup does.'
                : 'Inn displays only provider QR data for the locked amount. The QR disappears at terminal state; failed scans remain open until success, cancel, or expiry.')
            ->action(function (Reservation $record, array $data) use ($channel): void {
                $attempt = app(InitiateInPersonPayment::class)->handle(
                    $record,
                    $channel,
                    $data['target_id'],
                    PaymentRequestPurpose::from($data['purpose']),
                    $data['deposit_id'] ?? null,
                    isset($data['amount_minor']) ? (int) $data['amount_minor'] : null,
                    auth()->id(),
                    'filament-in-person:'.str()->uuid(),
                );
                Notification::make()->success()->title($channel === PaymentChannel::IntegratedTerminal ? 'Point order queued' : 'QR order ready')
                    ->body($channel === PaymentChannel::Qr && $attempt->qr_data_ciphertext !== null
                        ? 'Open Finance → Payment attempts to display and monitor the expiring provider QR.'
                        : 'Monitor the authoritative order state in Finance → Payment attempts.')
                    ->send();
            });
    }
}
