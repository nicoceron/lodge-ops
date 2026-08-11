<?php

namespace App\Filament\Resources\Reservations\Schemas;

use App\Enums\ReservationStatus;
use App\Filament\Support\LodgeOpsPresentation;
use App\Models\Guest;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ReservationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Stay')
                ->description('Core reservation details. Status changes use the guarded workflow actions.')
                ->columns(2)
                ->schema([
                    Select::make('property_id')
                        ->options(LodgeOpsPresentation::propertyOptions(...))
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('primary_guest_id')
                        ->label('Primary guest')
                        ->options(fn (): array => Guest::query()
                            ->orderBy('last_name')
                            ->orderBy('first_name')
                            ->get()
                            ->mapWithKeys(fn (Guest $guest): array => [
                                $guest->id => trim("{$guest->first_name} {$guest->last_name}").($guest->email ? " · {$guest->email}" : ''),
                            ])->all())
                        ->searchable(),
                    Select::make('program_id')
                        ->label('Primary package')
                        ->relationship('program', 'name')
                        ->helperText('Additional activities are assigned separately below the reservation.')
                        ->searchable()
                        ->preload(),
                    Select::make('companion_guest_ids')
                        ->label('Companions')
                        ->options(fn (): array => Guest::query()
                            ->orderBy('last_name')
                            ->orderBy('first_name')
                            ->get()
                            ->mapWithKeys(fn (Guest $guest): array => [
                                $guest->id => trim("{$guest->first_name} {$guest->last_name}").($guest->email ? " · {$guest->email}" : ''),
                            ])->all())
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->dehydrated(false),
                    TextInput::make('confirmation_number')
                        ->required()
                        ->default(fn (): string => 'RSV-'.Str::upper((string) Str::ulid()))
                        ->maxLength(80)
                        ->scopedUnique(ignoreRecord: true),
                    Select::make('status')
                        ->options(LodgeOpsPresentation::enumOptions(ReservationStatus::cases()))
                        ->default(ReservationStatus::Draft->value)
                        ->disabled()
                        ->dehydrated()
                        ->required(),
                    DateTimePicker::make('starts_at')
                        ->label('Arrival')
                        ->timezone(LodgeOpsPresentation::timezone())
                        ->seconds(false)
                        ->required(),
                    DateTimePicker::make('ends_at')
                        ->label('Departure')
                        ->timezone(LodgeOpsPresentation::timezone())
                        ->seconds(false)
                        ->after('starts_at')
                        ->required(),
                    TextInput::make('adults')
                        ->required()
                        ->integer()
                        ->minValue(1)
                        ->default(1),
                    TextInput::make('children')
                        ->required()
                        ->integer()
                        ->minValue(0)
                        ->default(0),
                    TextInput::make('source')
                        ->placeholder('direct, agent, partner')
                        ->maxLength(50),
                    Textarea::make('notes')
                        ->rows(4)
                        ->columnSpanFull(),
                ]),
            Section::make('Pricing')
                ->description('Amounts are stored in integer minor units to avoid rounding errors.')
                ->columns(2)
                ->schema([
                    TextInput::make('currency')
                        ->required()
                        ->length(3),
                    TextInput::make('subtotal_minor')
                        ->label('Subtotal (minor units)')
                        ->required()
                        ->integer()
                        ->minValue(0)
                        ->default(0),
                    TextInput::make('tax_minor')
                        ->label('Tax (minor units)')
                        ->required()
                        ->integer()
                        ->minValue(0)
                        ->default(0),
                    TextInput::make('total_minor')
                        ->label('Total (minor units)')
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Calculated from subtotal plus tax.'),
                ]),
        ]);
    }
}
