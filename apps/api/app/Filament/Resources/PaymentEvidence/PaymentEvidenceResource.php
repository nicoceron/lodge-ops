<?php

namespace App\Filament\Resources\PaymentEvidence;

use App\Enums\PaymentEvidenceStatus;
use App\Filament\Resources\PaymentEvidence\Pages\ListPaymentEvidence;
use App\Filament\Resources\PaymentEvidence\Pages\ViewPaymentEvidence;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\Deposit;
use App\Models\GuestPaymentEvidence;
use App\Models\ReservationChange;
use App\Services\Payments\CompleteManualExternalRefund;
use App\Services\Payments\ReviewRefundEvidence;
use App\Services\ReviewPaymentEvidence;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentEvidenceResource extends TenantResource
{
    protected static ?string $model = GuestPaymentEvidence::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 15;

    protected static ?string $modelLabel = 'transfer evidence';

    protected static ?string $pluralModelLabel = 'transfer evidence';

    protected static bool $canCreateRecords = false;

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    protected static ?string $viewCapability = 'canManageGuestMoney';

    protected static string $writeCapability = 'canManageGuestMoney';

    protected static ?string $propertyRelationship = 'reservation';

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', PaymentEvidenceStatus::Pending)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canReview(GuestPaymentEvidence $record): bool
    {
        return static::belongsToCurrentTenant($record)
            && static::canWrite()
            && auth()->user()?->can('review', $record) === true;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Transfer submission')->columns(2)->schema([
                TextEntry::make('reservation.confirmation_number')->label('Reservation')->copyable(),
                TextEntry::make('guest.email')->label('Guest'),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state): string => InnPresentation::label($state))
                    ->color(fn ($state): string => InnPresentation::statusColor($state)),
                TextEntry::make('amount_minor')->label('Declared amount')
                    ->money(fn (GuestPaymentEvidence $record): string => $record->currency, divideBy: 100),
                TextEntry::make('transfer_reference')->placeholder('No bank reference'),
                TextEntry::make('file_name'),
                TextEntry::make('content_type'),
                TextEntry::make('size_bytes')->numeric(),
                TextEntry::make('sha256')->copyable()->columnSpanFull(),
                TextEntry::make('submitted_at')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone()),
                TextEntry::make('reviewer.name')->placeholder('Not reviewed'),
                TextEntry::make('reviewer_note')->columnSpanFull()->placeholder('No decision note'),
                TextEntry::make('requested_information_note')->columnSpanFull()->placeholder('No information request'),
                TextEntry::make('payment.provider_reference')->label('Resulting payment')->placeholder('No payment created'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('submitted_at')->label('Submitted')->dateTime('M j · H:i', timezone: InnPresentation::timezone())->sortable(),
                TextColumn::make('reservation.confirmation_number')->label('Reservation')->searchable()->copyable(),
                TextColumn::make('guest.email')->label('Guest')->searchable(),
                TextColumn::make('amount_minor')->label('Amount')
                    ->money(fn (GuestPaymentEvidence $record): string => $record->currency, divideBy: 100)->sortable(),
                TextColumn::make('transfer_reference')->label('Reference')->placeholder('—')->searchable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state): string => InnPresentation::label($state))
                    ->color(fn ($state): string => InnPresentation::statusColor($state)),
                TextColumn::make('reviewer.name')->label('Reviewed by')->placeholder('Pending'),
            ])
            ->filters([
                SelectFilter::make('status')->options(InnPresentation::enumOptions(PaymentEvidenceStatus::cases()))->multiple(),
            ])
            ->recordActions([
                ViewAction::make(),
                static::downloadAction(),
                static::approveAction(),
                static::approveRefundAction(),
                static::completeRefundAction(),
                static::requestInformationAction(),
                static::rejectAction(),
                static::rejectRefundAction(),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->striped()
            ->emptyStateHeading('No transfer evidence waiting')
            ->emptyStateDescription('Guest bank-transfer submissions appear here for controlled review and reconciliation.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentEvidence::route('/'),
            'view' => ViewPaymentEvidence::route('/{record}'),
        ];
    }

    public static function downloadAction(): Action
    {
        return Action::make('download_evidence')
            ->label('Download')
            ->icon('heroicon-o-arrow-down-tray')
            ->authorize('download')
            ->url(fn (GuestPaymentEvidence $record): string => route('filament.admin.payment-evidence.download', [
                'tenant' => filament()->getTenant(),
                'evidence' => $record,
            ]))
            ->openUrlInNewTab();
    }

    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve & reconcile')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->authorize('review')
            ->visible(fn (GuestPaymentEvidence $record): bool => static::canReview($record)
                && $record->refund_change_id === null
                && in_array($record->status, [PaymentEvidenceStatus::Pending, PaymentEvidenceStatus::MoreInformationRequired], true))
            ->schema([
                Select::make('deposit_id')->label('Apply to deposit')
                    ->options(fn (GuestPaymentEvidence $record): array => Deposit::query()
                        ->where('reservation_id', $record->reservation_id)->where('status', 'due')->orderBy('due_at')->get()
                        ->mapWithKeys(fn (Deposit $deposit): array => [
                            $deposit->id => $deposit->currency.' '.number_format($deposit->amount_minor / 100, 2).' · '.($deposit->schedule_type ?? 'deposit'),
                        ])->all()),
                Textarea::make('reviewer_note')->label('Reconciliation note')->rows(3)->maxLength(5000),
            ])
            ->requiresConfirmation()
            ->action(function (GuestPaymentEvidence $record, array $data): void {
                app(ReviewPaymentEvidence::class)->approve($record, $data['deposit_id'] ?? null, auth()->id(), $data['reviewer_note'] ?? null);
                Notification::make()->success()->title('Evidence approved; one payment was reconciled')->send();
            });
    }

    public static function approveRefundAction(): Action
    {
        return Action::make('approve_refund_evidence')
            ->label('Approve refund evidence')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->authorize('review')
            ->visible(fn (GuestPaymentEvidence $record): bool => static::canReview($record)
                && $record->refund_change_id !== null
                && in_array($record->status, [PaymentEvidenceStatus::Pending, PaymentEvidenceStatus::MoreInformationRequired], true))
            ->schema([Textarea::make('reason')->required()->rows(3)->maxLength(500)])
            ->requiresConfirmation()
            ->action(function (GuestPaymentEvidence $record, array $data): void {
                app(ReviewRefundEvidence::class)->handle(auth()->user(), $record, 'approved', $data['reason'], 'filament-refund-evidence-approve:'.str()->uuid());
                Notification::make()->success()->title('Refund execution evidence approved')->send();
            });
    }

    public static function requestInformationAction(): Action
    {
        return Action::make('request_information')
            ->label('Request information')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->color('warning')
            ->authorize('review')
            ->visible(fn (GuestPaymentEvidence $record): bool => static::canReview($record)
                && $record->refund_change_id === null
                && $record->status !== PaymentEvidenceStatus::Approved)
            ->schema([Textarea::make('note')->required()->rows(3)->maxLength(5000)])
            ->action(function (GuestPaymentEvidence $record, array $data): void {
                app(ReviewPaymentEvidence::class)->requestMoreInformation($record, $data['note'], auth()->id());
                Notification::make()->success()->title('Information request recorded for the guest')->send();
            });
    }

    public static function completeRefundAction(): Action
    {
        return Action::make('complete_manual_refund')
            ->label('Complete manual refund')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->authorize('review')
            ->visible(function (GuestPaymentEvidence $record): bool {
                if (! static::canReview($record) || $record->refund_change_id === null || $record->status !== PaymentEvidenceStatus::Approved) {
                    return false;
                }

                return ReservationChange::query()->whereKey($record->refund_change_id)
                    ->where('type', 'refund_requested')->where('status', 'requested')->exists();
            })
            ->schema([
                TextInput::make('execution_reference')->label('External execution reference')->required()->maxLength(160),
            ])
            ->requiresConfirmation()
            ->modalDescription('Confirm only after the external refund has actually been executed. Inn records the evidence; it does not call or verify the external processor.')
            ->action(function (GuestPaymentEvidence $record, array $data): void {
                $request = ReservationChange::query()->findOrFail($record->refund_change_id);
                app(CompleteManualExternalRefund::class)->handle(
                    auth()->user(),
                    $request,
                    $data['execution_reference'],
                    'filament-manual-refund-complete:'.str()->uuid(),
                    $record,
                );
                Notification::make()->success()->title('Manual refund completion recorded from approved evidence')->send();
            });
    }

    public static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->authorize('review')
            ->visible(fn (GuestPaymentEvidence $record): bool => static::canReview($record)
                && $record->refund_change_id === null
                && $record->status !== PaymentEvidenceStatus::Approved)
            ->schema([Textarea::make('note')->required()->rows(3)->maxLength(5000)])
            ->requiresConfirmation()
            ->action(function (GuestPaymentEvidence $record, array $data): void {
                app(ReviewPaymentEvidence::class)->reject($record, $data['note'], auth()->id());
                Notification::make()->success()->title('Evidence rejected; no payment was created')->send();
            });
    }

    public static function rejectRefundAction(): Action
    {
        return Action::make('reject_refund_evidence')
            ->label('Reject refund evidence')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->authorize('review')
            ->visible(fn (GuestPaymentEvidence $record): bool => static::canReview($record)
                && $record->refund_change_id !== null
                && in_array($record->status, [PaymentEvidenceStatus::Pending, PaymentEvidenceStatus::MoreInformationRequired], true))
            ->schema([Textarea::make('reason')->required()->rows(3)->maxLength(500)])
            ->requiresConfirmation()
            ->action(function (GuestPaymentEvidence $record, array $data): void {
                app(ReviewRefundEvidence::class)->handle(auth()->user(), $record, 'rejected', $data['reason'], 'filament-refund-evidence-reject:'.str()->uuid());
                Notification::make()->success()->title('Refund execution evidence rejected')->send();
            });
    }
}
