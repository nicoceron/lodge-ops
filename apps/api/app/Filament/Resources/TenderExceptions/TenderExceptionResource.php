<?php

namespace App\Filament\Resources\TenderExceptions;

use App\Data\Payments\FrontDeskPaymentInput;
use App\Enums\PaymentChannel;
use App\Filament\Resources\TenantResource;
use App\Filament\Resources\TenderExceptions\Pages\ManageTenderExceptions;
use App\Filament\Support\InnPresentation;
use App\Models\PaymentTenderDetail;
use App\Services\Payments\ResolveTenderDuplicate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TenderExceptionResource extends TenantResource
{
    protected static ?string $model = PaymentTenderDetail::class;

    protected static ?string $navigationLabel = 'Tender exceptions';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 13;

    protected static bool $canCreateRecords = false;

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    protected static ?string $viewCapability = 'canViewFinance';

    protected static string $writeCapability = 'canManageMoney';

    protected static ?string $propertyRelationship = 'property';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('state', ['duplicate_review', 'identity_exception', 'needs_corrected_identity']);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('received_at')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone()),
            TextColumn::make('reservation.confirmation_number')->label('Reservation'),
            TextColumn::make('state')->badge(),
            TextColumn::make('amount_minor')->money(fn (PaymentTenderDetail $record): string => $record->currency, divideBy: 100),
            TextColumn::make('processor_alias'),
            TextColumn::make('terminal_identifier'),
            TextColumn::make('transaction_reference')->copyable(),
            TextColumn::make('duplicate_of_id')->label('Possible duplicate')->copyable()->placeholder('—'),
            TextColumn::make('review_reason')->wrap(),
        ])->recordActions([
            Action::make('resolve')->authorize('resolve')->schema([
                Select::make('decision')->options([
                    'confirmed_duplicate' => 'Confirmed duplicate',
                    'needs_corrected_identity' => 'Needs corrected identity',
                    'corrected_identity' => 'Retry corrected identity',
                    'dismissed_unposted' => 'Dismiss unposted draft',
                ])->required()->live(),
                TextInput::make('processor_alias')->required(fn ($get): bool => $get('decision') === 'corrected_identity')->visible(fn ($get): bool => $get('decision') === 'corrected_identity')->maxLength(80),
                TextInput::make('merchant_account_alias')->required(fn ($get): bool => $get('decision') === 'corrected_identity')->visible(fn ($get): bool => $get('decision') === 'corrected_identity')->maxLength(120),
                TextInput::make('terminal_identifier')->required(fn ($get): bool => $get('decision') === 'corrected_identity')->visible(fn ($get): bool => $get('decision') === 'corrected_identity')->maxLength(80),
                TextInput::make('transaction_reference')->required(fn ($get): bool => $get('decision') === 'corrected_identity')->visible(fn ($get): bool => $get('decision') === 'corrected_identity')->maxLength(160),
                Textarea::make('reason')->required()->maxLength(500),
            ])->requiresConfirmation()->action(function (PaymentTenderDetail $record, array $data): void {
                $key = 'filament-tender-resolution:'.str()->uuid();
                $corrected = $data['decision'] === 'corrected_identity' ? new FrontDeskPaymentInput(
                    reservationId: $record->reservation_id,
                    channel: PaymentChannel::ExternalTerminal,
                    amountMinor: $record->amount_minor,
                    idempotencyKey: 'tender-retry:'.hash('sha256', $key),
                    depositId: $record->deposit_id,
                    processorAlias: $data['processor_alias'],
                    merchantAccountAlias: $data['merchant_account_alias'],
                    terminalIdentifier: $data['terminal_identifier'],
                    transactionReference: $data['transaction_reference'],
                ) : null;
                app(ResolveTenderDuplicate::class)->handle(auth()->user(), $record, $data['decision'], $data['reason'], $key, $corrected);
            }),
        ])->defaultSort('received_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageTenderExceptions::route('/')];
    }
}
