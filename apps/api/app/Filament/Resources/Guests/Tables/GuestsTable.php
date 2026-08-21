<?php

namespace App\Filament\Resources\Guests\Tables;

use App\Models\Guest;
use App\Services\GuestMergeService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class GuestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Guest')
                    ->state(fn (Guest $record): string => trim("{$record->first_name} {$record->last_name}"))
                    ->description(fn (Guest $record): ?string => $record->email)
                    ->searchable(['first_name', 'last_name', 'email'])
                    ->sortable(query: fn ($query, string $direction) => $query
                        ->orderBy('last_name', $direction)
                        ->orderBy('first_name', $direction)),
                TextColumn::make('phone')
                    ->icon('heroicon-m-phone')
                    ->placeholder('Not provided')
                    ->searchable(),
                TextColumn::make('language')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => strtoupper($state ?: '—'))
                    ->color('gray'),
                TextColumn::make('reservations_count')
                    ->label('Stays')
                    ->counts('reservations')
                    ->badge()
                    ->sortable(),
                IconColumn::make('marketing_consent')
                    ->label('Marketing')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label('Last updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('language')
                    ->options([
                        'en' => 'English',
                        'es' => 'Español',
                        'fr' => 'Français',
                        'pt' => 'Português',
                    ]),
                TernaryFilter::make('marketing_consent')
                    ->label('Marketing consent')
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('merge')
                    ->label('Merge duplicate')
                    ->icon('heroicon-o-arrows-pointing-in')
                    ->color('warning')
                    ->visible(fn (Guest $record): bool => $record->merged_into_id === null)
                    ->schema([
                        Select::make('target_guest_id')
                            ->label('Keep this canonical guest')
                            ->helperText('Stays, proposals, communications, companion links, and portal history move to the canonical profile. The duplicate becomes a PII-cleared audit tombstone.')
                            ->options(fn (Guest $record): array => Guest::query()->whereKeyNot($record->id)->whereNull('merged_into_id')
                                ->orderBy('last_name')->orderBy('first_name')->get()
                                ->mapWithKeys(fn (Guest $guest): array => [$guest->id => trim("{$guest->first_name} {$guest->last_name}").($guest->email ? " · {$guest->email}" : '')])->all())
                            ->searchable()->required(),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Guest $record, array $data): void {
                        $target = Guest::query()->findOrFail($data['target_guest_id']);
                        app(GuestMergeService::class)->merge($record, $target);
                        Notification::make()->success()->title('Duplicate guest merged')->body('The canonical profile now owns the operational history; an audit alias was retained.')->send();
                    }),
            ])
            ->defaultSort('last_name')
            ->striped()
            ->emptyStateHeading('No guests yet')
            ->emptyStateDescription('Guest profiles will become the shared source of service preferences and stay history.')
            ->emptyStateIcon('heroicon-o-user-group');
    }
}
