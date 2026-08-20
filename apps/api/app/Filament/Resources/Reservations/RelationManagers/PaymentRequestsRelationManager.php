<?php

namespace App\Filament\Resources\Reservations\RelationManagers;

use App\Enums\PaymentRequestState;
use App\Filament\Support\InnPresentation;
use App\Models\PaymentRequest;
use App\Services\Payments\RevokeOrSupersedePaymentRequest;
use App\Services\Payments\RotateOrResendPaymentRequest;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class PaymentRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentRequests';

    protected static ?string $title = 'Secure payment requests';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label('Issued')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->sortable(),
            TextColumn::make('purpose')->badge()->formatStateUsing(InnPresentation::label(...)),
            TextColumn::make('source_amount_minor')->label('Amount')->money(fn (PaymentRequest $record): string => $record->source_currency, divideBy: 100),
            TextColumn::make('state')->badge()->formatStateUsing(InnPresentation::label(...))->color(fn ($state): string => InnPresentation::statusColor($state)),
            TextColumn::make('expires_at')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone()),
            TextColumn::make('attempts_count')->counts('attempts')->label('Attempts'),
        ])->recordActions([
            Action::make('rotate')->label('Rotate / resend')->icon('heroicon-o-arrow-path')
                ->visible(fn (PaymentRequest $record): bool => $record->state === PaymentRequestState::Open && Gate::allows('update', $record))
                ->requiresConfirmation()->action(function (PaymentRequest $record): void {
                    $issued = app(RotateOrResendPaymentRequest::class)->handle($record, true, auth()->id());
                    Notification::make()->success()->title('Link rotated')
                        ->body(url('/pay/'.$issued->token).' · The previous link is invalid. Copy this link now.')
                        ->persistent()->send();
                }),
            Action::make('revoke')->color('danger')->icon('heroicon-o-no-symbol')
                ->visible(fn (PaymentRequest $record): bool => in_array($record->state, [PaymentRequestState::Open, PaymentRequestState::Processing], true) && Gate::allows('update', $record))
                ->schema([Textarea::make('reason')->required()->maxLength(500)])
                ->action(function (PaymentRequest $record, array $data): void {
                    app(RevokeOrSupersedePaymentRequest::class)->handle($record, $data['reason'], auth()->id());
                    Notification::make()->success()->title('Payment request revoked')->send();
                }),
        ])->defaultSort('created_at', 'desc');
    }
}
