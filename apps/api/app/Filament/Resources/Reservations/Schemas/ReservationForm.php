<?php

namespace App\Filament\Resources\Reservations\Schemas;

use App\Enums\ReservationStatus;
use App\Filament\Support\InnPresentation;
use App\Models\Guest;
use App\Models\RatePlan;
use App\Models\RatePlanService;
use App\Models\ResourceCategory;
use App\Services\AvailabilityQuery;
use App\Services\BookingQuoteService;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Throwable;

class ReservationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('1 · Search live availability')
                ->description('Choose the stay first. Inventory and occupancy are checked again under a database lock when the hold is created.')
                ->columnSpanFull()
                ->visibleOn('create')->columns(2)->schema([
                    Select::make('property_id')->label('Property')->options(InnPresentation::propertyOptions(...))->searchable()->preload()->live()->required(),
                    Select::make('resource_category_id')->label('Accommodation category')
                        ->options(fn (Get $get): array => ResourceCategory::query()
                            ->when($get('property_id'), fn ($query, $propertyId) => $query->where('property_id', $propertyId))
                            ->where('counts_as_stay', true)->where('is_active', true)
                            ->orderBy('sort_order')->pluck('name', 'id')->all())
                        ->searchable()->preload()->live()->required(),
                    DateTimePicker::make('starts_at')->label('Arrival')->timezone(InnPresentation::timezone())->seconds(false)->live()->required(),
                    DateTimePicker::make('ends_at')->label('Departure')->timezone(InnPresentation::timezone())->seconds(false)->after('starts_at')->live()->required(),
                    TextInput::make('adults')->integer()->minValue(1)->default(1)->live(onBlur: true)->required(),
                    TextInput::make('children')->integer()->minValue(0)->default(0)->live(onBlur: true)->required(),
                    TextInput::make('infants')->integer()->minValue(0)->default(0)->live(onBlur: true)->required(),
                    Select::make('resource_id')->label('Exact accommodation')
                        ->helperText('Optional. Leave blank to hold the category and assign the exact room later.')
                        ->options(fn (Get $get): array => self::availableResourceOptions($get))->searchable()->live(),
                ]),
            Section::make('2 · Server-priced quote')
                ->description('Rates, taxes, deposit, and cancellation terms are snapshotted. Staff never type reservation totals.')
                ->columnSpanFull()
                ->visibleOn('create')->columns(2)->schema([
                    Select::make('rate_plan_id')->label('Rate plan')
                        ->options(fn (Get $get): array => RatePlan::query()
                            ->when($get('property_id'), fn ($query, $propertyId) => $query->where('property_id', $propertyId))
                            ->where('is_active', true)->where('state', 'published')->orderBy('name')->get()
                            ->mapWithKeys(fn (RatePlan $plan): array => [$plan->id => "{$plan->name} · {$plan->currency}"])->all())
                        ->searchable()->preload()->live()->required(),
                    Select::make('program_id')->label('Primary package / activity')->relationship('program', 'name')->searchable()->preload()->live(),
                    Select::make('optional_service_ids')->label('Optional add-ons')->multiple()->searchable()->live()
                        ->options(fn (Get $get): array => RatePlanService::query()->with('catalogItem')
                            ->when($get('rate_plan_id'), fn ($query, $id) => $query->where('rate_plan_id', $id))
                            ->where('selection_type', 'optional')->where('is_active', true)->get()
                            ->mapWithKeys(fn (RatePlanService $service): array => [$service->catalog_item_id => $service->catalogItem->name])->all()),
                    TextInput::make('voucher_code')->label('Promotion / voucher code')->maxLength(64)->live(onBlur: true),
                    Placeholder::make('quote_preview')->label('Live quote')->content(fn (Get $get): HtmlString => self::quotePreview($get))->columnSpanFull(),
                ]),
            Section::make('3 · Guest and reservation details')
                ->description('Select a repeat guest or create a guest inline. Submitting creates an expiring, conflict-safe hold.')
                ->columnSpanFull()
                ->visibleOn('create')->columns(2)->schema([
                    Select::make('primary_guest_id')->label('Existing primary guest')->options(self::guestOptions(...))->searchable()->preload()->live(),
                    Select::make('companion_guest_ids')->label('Existing companions')->options(self::guestOptions(...))->multiple()->searchable()->preload(),
                    TextInput::make('guest_first_name')->label('New guest first name')->required(fn (Get $get): bool => blank($get('primary_guest_id')))->maxLength(100),
                    TextInput::make('guest_last_name')->label('New guest last name')->maxLength(100),
                    TextInput::make('guest_email')->label('New guest email')->email()->maxLength(255),
                    TextInput::make('guest_phone')->label('New guest phone')->tel()->maxLength(40),
                    TextInput::make('guest_language')->label('Language')->maxLength(12),
                    TextInput::make('guest_dietary')->label('Dietary / allergy notes')->maxLength(500),
                    TextInput::make('source')->placeholder('direct, agent, partner')->maxLength(50),
                    Textarea::make('notes')->rows(4)->columnSpanFull(),
                ]),
            Section::make('Stay')
                ->description('Guest assignment and notes may be edited here. Dates, occupancy, package, resource, and price change only through Amend stay or Move room.')
                ->columnSpanFull()
                ->visibleOn('edit')->columns(2)->schema([
                    Select::make('property_id')->label('Property')->options(InnPresentation::propertyOptions(...))->disabled()->dehydrated(false),
                    Select::make('primary_guest_id')->label('Primary guest')->options(self::guestOptions(...))->searchable(),
                    Select::make('companion_guest_ids')->label('Companions')->options(self::guestOptions(...))->multiple()->searchable()->preload()->dehydrated(false),
                    TextInput::make('confirmation_number')->disabled()->dehydrated(false),
                    Select::make('status')->options(InnPresentation::enumOptions(ReservationStatus::cases()))->disabled()->dehydrated(false),
                    DateTimePicker::make('starts_at')->label('Arrival')->timezone(InnPresentation::timezone())->seconds(false)->disabled()->dehydrated(false),
                    DateTimePicker::make('ends_at')->label('Departure')->timezone(InnPresentation::timezone())->seconds(false)->disabled()->dehydrated(false),
                    TextInput::make('adults')->disabled()->dehydrated(false),
                    TextInput::make('children')->disabled()->dehydrated(false),
                    TextInput::make('infants')->disabled()->dehydrated(false),
                    TextInput::make('source')->maxLength(50),
                    Textarea::make('notes')->rows(4)->columnSpanFull(),
                ]),
            Section::make('Committed price snapshot')
                ->description('These values reflect the accepted quote. Use a guarded amendment operation for post-confirmation changes.')
                ->columnSpanFull()
                ->visibleOn('edit')->columns(2)->schema([
                    TextInput::make('currency')->disabled()->dehydrated(false),
                    TextInput::make('subtotal_minor')->label('Subtotal (minor units)')->integer()->disabled()->dehydrated(false),
                    TextInput::make('tax_minor')->label('Tax (minor units)')->integer()->disabled()->dehydrated(false),
                    TextInput::make('total_minor')->label('Total (minor units)')->disabled()->dehydrated(false),
                ]),
        ]);
    }

    /** @return array<string, string> */
    private static function guestOptions(): array
    {
        return Guest::query()->orderBy('last_name')->orderBy('first_name')->get()
            ->mapWithKeys(fn (Guest $guest): array => [
                $guest->id => trim("{$guest->first_name} {$guest->last_name}").($guest->email ? " · {$guest->email}" : ''),
            ])->all();
    }

    /** @return array<string, string> */
    private static function availableResourceOptions(Get $get): array
    {
        if (! $get('property_id') || ! $get('resource_category_id') || ! $get('starts_at') || ! $get('ends_at')) {
            return [];
        }
        try {
            $result = app(AvailabilityQuery::class)->forStay(
                $get('property_id'), $get('starts_at'), $get('ends_at'),
                max(1, (int) $get('adults') + (int) $get('children')), $get('resource_category_id'),
            );

            return collect($result['resources'])->where('available', true)->mapWithKeys(fn (array $resource): array => [
                $resource['id'] => $resource['name'].' · sleeps '.$resource['capacity'],
            ])->all();
        } catch (Throwable) {
            return [];
        }
    }

    private static function quotePreview(Get $get): HtmlString
    {
        if (! $get('property_id') || ! $get('resource_category_id') || ! $get('rate_plan_id') || ! $get('starts_at') || ! $get('ends_at')) {
            return new HtmlString('<span class="text-gray-500">Complete the stay and rate fields to calculate the quote.</span>');
        }
        try {
            $quote = app(BookingQuoteService::class)->preview([
                'property_id' => $get('property_id'), 'resource_category_id' => $get('resource_category_id'),
                'resource_id' => $get('resource_id'), 'rate_plan_id' => $get('rate_plan_id'), 'program_id' => $get('program_id'),
                'starts_at' => $get('starts_at'), 'ends_at' => $get('ends_at'),
                'adults' => (int) $get('adults'), 'children' => (int) $get('children'), 'infants' => (int) $get('infants'),
                'optional_services' => collect((array) $get('optional_service_ids'))->map(fn (string $id): array => ['id' => $id, 'quantity' => 1])->all(),
                'voucher_code' => $get('voucher_code'),
            ]);
            $currency = e($quote['currency']);
            $lines = collect($quote['lines'])->map(fn (array $line): string => '<li><strong>'.e($line['description']).'</strong> · '.$currency.' '.number_format($line['gross_amount_minor'] / 100, 2).'<br><span class="text-xs text-gray-500">'.e($line['explanation'] ?? '').'</span></li>')->implode('');
            $deposit = $quote['deposit_policy_snapshot']['name'] ?? 'No deposit policy';
            $cancellation = $quote['cancellation_policy_snapshot']['name'] ?? 'No cancellation policy';

            return new HtmlString('<div class="space-y-2"><ul>'.$lines.'</ul><strong>Total · '.$currency.' '.number_format($quote['total_minor'] / 100, 2).'</strong><div class="text-sm text-gray-500">'.e($deposit).' · '.e($cancellation).'</div></div>');
        } catch (Throwable $exception) {
            return new HtmlString('<span class="text-danger-600">'.e($exception->getMessage()).'</span>');
        }
    }
}
