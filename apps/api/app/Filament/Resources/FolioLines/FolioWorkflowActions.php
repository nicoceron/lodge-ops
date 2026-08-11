<?php

namespace App\Filament\Resources\FolioLines;

use App\Enums\FolioLineType;
use App\Filament\Support\LodgeOpsPresentation;
use App\Models\FolioLine;
use App\Models\Reservation;
use App\Services\FolioService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

final class FolioWorkflowActions
{
    public static function append(): Action
    {
        return Action::make('post_folio_entry')
            ->label('Post folio entry')
            ->icon('heroicon-o-plus')
            ->authorize('create', FolioLine::class)
            ->visible(FolioLineResource::canAppend(...))
            ->schema([
                Select::make('reservation_id')
                    ->options(LodgeOpsPresentation::reservationOptions(...))
                    ->searchable()
                    ->required(),
                Select::make('type')
                    ->options([
                        FolioLineType::Charge->value => 'Charge',
                        FolioLineType::Adjustment->value => 'Adjustment / credit',
                    ])->required(),
                TextInput::make('description')->required()->maxLength(500),
                TextInput::make('quantity')->numeric()->step(0.001)->minValue(0.001)->default(1)->required(),
                TextInput::make('unit_amount_minor')
                    ->label('Unit amount (minor units)')
                    ->helperText('Use a negative amount for a credit adjustment.')
                    ->integer()
                    ->required(),
            ])
            ->action(function (array $data): void {
                app(FolioService::class)->append(
                    Reservation::query()->findOrFail($data['reservation_id']),
                    FolioLineType::from($data['type']),
                    $data['description'],
                    (int) round(((float) $data['quantity']) * 1000),
                    (int) $data['unit_amount_minor'],
                    auth()->id(),
                );
                Notification::make()->success()->title('Append-only folio entry posted')->send();
            });
    }

    public static function reverse(): Action
    {
        return Action::make('reverse')
            ->label('Reverse entry')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->authorize('reverse')
            ->schema([
                Textarea::make('reason')->required()->maxLength(5000)->rows(3),
            ])
            ->requiresConfirmation()
            ->visible(fn (FolioLine $record): bool => FolioLineResource::canReverse($record))
            ->action(function (FolioLine $record, array $data): void {
                app(FolioService::class)->reverse($record, $data['reason'], auth()->id());
                Notification::make()->success()->title('Balancing reversal entry posted')->send();
            });
    }
}
