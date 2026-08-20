<?php

namespace App\Filament\Resources\Reservations;

use App\Enums\AllocationStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Allocation;
use App\Models\Payment;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationChange;
use App\Models\Resource;
use App\Models\ResourceCategory;
use App\Services\AmendReservation;
use App\Services\CompleteRefund;
use App\Services\QuoteExplanationService;
use App\Services\ReallocateResource;
use App\Services\RequestRefund;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Gate;

final class ReservationChangeActions
{
    /** @return array<Action> */
    public static function make(): array
    {
        return [self::explainQuote(), self::amend(), self::move(), self::requestRefund(), self::completeRefund()];
    }

    private static function explainQuote(): Action
    {
        return Action::make('explainQuote')->label('Historical quote explanation')->icon('heroicon-o-document-magnifying-glass')
            ->visible(fn (Reservation $record): bool => $record->booking_quote_id !== null
                && auth()->user()?->can('view', $record->bookingQuote) === true)
            ->modalHeading('Historical quote explanation')
            ->modalDescription(fn (Reservation $record): string => collect(app(QuoteExplanationService::class)->project($record->bookingQuote))
                ->only(['currency', 'subtotal_minor', 'discount_minor', 'tax_minor', 'total_minor'])
                ->map(fn ($value, $key): string => str($key)->replace('_', ' ')->title().': '.$value)->implode("\n"))
            ->modalContent(fn (Reservation $record) => view('filament.reservations.quote-history', [
                'history' => app(QuoteExplanationService::class)->project($record->bookingQuote),
            ]))
            ->modalSubmitAction(false)->modalCancelActionLabel('Close');
    }

    private static function amend(): Action
    {
        return Action::make('amendReservation')
            ->label('Amend stay')
            ->icon('heroicon-o-calendar-days')
            ->color('warning')
            ->authorize('update')
            ->visible(fn (Reservation $record): bool => in_array($record->status, [ReservationStatus::Hold, ReservationStatus::Confirmed], true))
            ->fillForm(fn (Reservation $record): array => [
                'rate_plan_id' => $record->bookingQuote?->rate_plan_id,
                'resource_category_id' => $record->allocations()->where('status', '!=', AllocationStatus::Released)->value('requested_category_id'),
                'resource_id' => $record->allocations()->where('status', '!=', AllocationStatus::Released)->value('resource_id'),
                'program_id' => $record->program_id,
                'starts_at' => $record->starts_at,
                'ends_at' => $record->ends_at,
                'adults' => $record->adults,
                'children' => $record->children,
                'infants' => $record->infants,
            ])
            ->schema([
                Select::make('rate_plan_id')->label('Rate plan')
                    ->options(fn (Reservation $record): array => RatePlan::query()->where('property_id', $record->property_id)
                        ->where('is_active', true)->where('state', 'published')->orderBy('name')->pluck('name', 'id')->all())->required()->searchable(),
                Select::make('resource_category_id')->label('Accommodation category')->live()
                    ->options(fn (Reservation $record): array => ResourceCategory::query()->where('property_id', $record->property_id)
                        ->where('counts_as_stay', true)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())->required()->searchable(),
                Select::make('resource_id')->label('Exact accommodation')
                    ->helperText('Optional. Availability is checked again under lock.')
                    ->options(fn (Reservation $record, Get $get): array => Resource::query()->where('property_id', $record->property_id)
                        ->when($get('resource_category_id'), fn ($query, $category) => $query->where('category_id', $category))
                        ->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                Select::make('program_id')->label('Primary package')->relationship('program', 'name')->searchable(),
                DateTimePicker::make('starts_at')->label('Arrival')->required()->seconds(false),
                DateTimePicker::make('ends_at')->label('Departure')->required()->seconds(false)->after('starts_at'),
                TextInput::make('adults')->integer()->minValue(1)->required(),
                TextInput::make('children')->integer()->minValue(0)->required(),
                TextInput::make('infants')->integer()->minValue(0)->required(),
            ])
            ->modalDescription('Submitting creates and commits a fresh server-priced quote. The previous quote, allocation, and folio entries remain in history.')
            ->action(function (Reservation $record, array $data): void {
                app(AmendReservation::class)->handle($record, $data, auth()->id());
                Notification::make()->success()->title('Reservation amended and re-priced')->send();
            });
    }

    private static function move(): Action
    {
        return Action::make('moveResource')
            ->label('Move room')
            ->icon('heroicon-o-arrows-right-left')
            ->color('info')
            ->authorize('reallocate')
            ->visible(fn (Reservation $record): bool => in_array($record->status, [ReservationStatus::Hold, ReservationStatus::Confirmed, ReservationStatus::CheckedIn], true))
            ->fillForm(fn (Reservation $record): array => [
                'allocation_id' => $record->allocations()->where('status', '!=', AllocationStatus::Released)->orderBy('created_at')->value('id'),
            ])
            ->schema([
                Select::make('allocation_id')->label('Current assignment')->live()
                    ->options(fn (Reservation $record): array => $record->allocations()->with(['resource', 'requestedCategory'])
                        ->where('status', '!=', AllocationStatus::Released)->get()
                        ->mapWithKeys(fn (Allocation $allocation): array => [
                            $allocation->id => $allocation->assignmentLabel(),
                        ])->all())->required(),
                Select::make('resource_id')->label('New resource')
                    ->options(function (Reservation $record, Get $get): array {
                        $allocation = $get('allocation_id') ? Allocation::query()->find($get('allocation_id')) : null;

                        return Resource::query()->where('property_id', $record->property_id)
                            ->when($allocation?->requested_category_id, fn ($query, $category) => $query->where('category_id', $category))
                            ->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
                    })->disabled(fn (Get $get): bool => blank($get('allocation_id')))->required()->searchable(),
                Textarea::make('reason')->label('Move reason')->maxLength(500)->rows(3),
            ])
            ->action(function (Reservation $record, array $data): void {
                app(ReallocateResource::class)->handle(
                    $record,
                    Allocation::query()->findOrFail($data['allocation_id']),
                    Resource::query()->findOrFail($data['resource_id']),
                    auth()->id(),
                    reason: $data['reason'] ?? null,
                );
                Notification::make()->success()->title('Resource moved without overwriting allocation history')->send();
            });
    }

    private static function requestRefund(): Action
    {
        return Action::make('requestRefund')
            ->label('Request refund')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (Reservation $record): bool => Gate::allows('requestRefund', $record)
                && $record->payments()->where('status', PaymentStatus::Succeeded)->exists())
            ->schema([
                Select::make('payment_id')->label('Source payment')
                    ->options(fn (Reservation $record): array => $record->payments()->where('status', PaymentStatus::Succeeded)
                        ->get()->mapWithKeys(fn (Payment $payment): array => [
                            $payment->id => strtoupper($payment->currency).' '.number_format($payment->amount_minor / 100, 2).' · '.str($payment->method)->headline(),
                        ])->all())->required(),
                TextInput::make('amount_minor')->label('Amount · minor units')->integer()->minValue(1)->required(),
                Textarea::make('reason')->required()->maxLength(500)->rows(3),
            ])
            ->action(function (Reservation $record, array $data): void {
                app(RequestRefund::class)->handle(
                    $record,
                    Payment::query()->findOrFail($data['payment_id']),
                    (int) $data['amount_minor'],
                    $data['reason'],
                    auth()->id(),
                );
                Notification::make()->success()->title('Refund requested for finance completion')->send();
            });
    }

    private static function completeRefund(): Action
    {
        return Action::make('completeRefund')
            ->label('Complete refund')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (Reservation $record): bool => Gate::allows('completeRefund', $record)
                && $record->changes()->where('type', 'refund_requested')->where('status', 'requested')
                    ->whereDoesntHave('events', fn ($query) => $query->where('type', 'refund_completed'))->exists())
            ->schema([
                Select::make('refund_request_id')->label('Open refund request')
                    ->options(fn (Reservation $record): array => $record->changes()->where('type', 'refund_requested')->where('status', 'requested')
                        ->whereDoesntHave('events', fn ($query) => $query->where('type', 'refund_completed'))->get()
                        ->mapWithKeys(fn (ReservationChange $change): array => [
                            $change->id => strtoupper((string) $change->currency).' '.number_format($change->amount_minor / 100, 2).' · '.data_get($change->metadata, 'reason'),
                        ])->all())->required(),
                TextInput::make('reference')->label('Internal / provider reference')->required()->maxLength(255),
            ])
            ->action(function (Reservation $record, array $data): void {
                app(CompleteRefund::class)->handle(
                    ReservationChange::query()->where('reservation_id', $record->id)->findOrFail($data['refund_request_id']),
                    $data['reference'],
                    auth()->id(),
                );
                Notification::make()->success()->title('Refund completed and posted to the folio')->send();
            });
    }
}
