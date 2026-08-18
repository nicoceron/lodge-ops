<?php

namespace App\Filament\Resources\SurveyResponses\Tables;

use App\Filament\Support\InnPresentation;
use App\Models\Program;
use App\Models\Property;
use App\Models\Survey;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SurveyResponsesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('responded_at')
                    ->label('Responded')
                    ->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())
                    ->sortable(),
                TextColumn::make('reservation.property.name')
                    ->label('Property')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('reservation.program.name')
                    ->label('Program')
                    ->placeholder('No program')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('reservation.confirmation_number')
                    ->label('Reservation')
                    ->searchable(),
                TextColumn::make('score')
                    ->label('Rating')
                    ->badge()
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : "{$state} / 5")
                    ->color(fn (?int $state): string => match (true) {
                        $state >= 4 => 'success',
                        $state === 3 => 'warning',
                        default => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('comment')
                    ->label('Comments')
                    ->state(fn (Survey $record): ?string => data_get($record->answers, 'comment'))
                    ->placeholder('No comments')
                    ->limit(80)
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('property')
                    ->label('Property')
                    ->options(fn (): array => Property::query()
                        ->where('is_active', true)
                        ->when(
                            app(TenantContext::class)->membership()?->property_id,
                            fn (Builder $query, string $propertyId): Builder => $query->whereKey($propertyId),
                        )
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query) => $query->whereHas(
                            'reservation',
                            fn (Builder $reservation) => $reservation->where('property_id', $data['value']),
                        ),
                    )),
                SelectFilter::make('program')
                    ->label('Program')
                    ->options(fn (): array => Program::query()
                        ->when(
                            app(TenantContext::class)->membership()?->property_id,
                            fn (Builder $query, string $propertyId): Builder => $query->where('property_id', $propertyId),
                        )
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query) => $query->whereHas(
                            'reservation',
                            fn (Builder $reservation) => $reservation->where('program_id', $data['value']),
                        ),
                    )),
                Filter::make('date')
                    ->label('Response date')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['from'] ?? null), fn (Builder $query) => $query->whereDate('responded_at', '>=', $data['from']))
                        ->when(filled($data['until'] ?? null), fn (Builder $query) => $query->whereDate('responded_at', '<=', $data['until']))),
                SelectFilter::make('rating')
                    ->label('Rating')
                    ->options([
                        1 => '1 / 5',
                        2 => '2 / 5',
                        3 => '3 / 5',
                        4 => '4 / 5',
                        5 => '5 / 5',
                    ])
                    ->attribute('score'),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('responded_at', 'desc')
            ->striped()
            ->emptyStateHeading('No survey responses')
            ->emptyStateDescription('Completed guest feedback will appear here after a response is submitted.')
            ->emptyStateIcon('heroicon-o-chat-bubble-left-right');
    }
}
