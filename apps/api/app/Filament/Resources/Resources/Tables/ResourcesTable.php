<?php

namespace App\Filament\Resources\Resources\Tables;

use App\Enums\HousekeepingStatus;
use App\Enums\ResourceKind;
use App\Filament\Support\InnPresentation;
use App\Models\Resource;
use App\Models\ResourceCategory;
use App\Services\HousekeepingService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ResourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->description(fn (Resource $record): string => $record->code)
                    ->searchable(['name', 'code'])
                    ->sortable(),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->badge()
                    ->color(fn (Resource $record): string => $record->category->kind->color())
                    ->description(fn (Resource $record): string => $record->category->kind->singular())
                    ->sortable(),
                TextColumn::make('property.name')
                    ->label('Property')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('capacity')
                    ->alignCenter()
                    ->numeric()
                    ->sortable(),
                TextColumn::make('housekeeping_status')
                    ->label('Housekeeping')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state?->label() ?? 'Not tracked')
                    ->color(fn ($state): string => $state?->color() ?? 'gray')
                    ->placeholder('—'),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('property')
                    ->relationship('property', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('kind')
                    ->label('Kind')
                    ->options(InnPresentation::enumOptions(ResourceKind::cases()))
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('category', fn ($category) => $category->where('kind', $data['value']))
                        : $query),
                SelectFilter::make('category_id')
                    ->label('Category')
                    ->options(fn (): array => ResourceCategory::query()
                        ->orderBy('sort_order')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->multiple(),
                TernaryFilter::make('is_active')
                    ->label('Active resources')
                    ->native(false),
                SelectFilter::make('housekeeping_status')
                    ->options(collect(HousekeepingStatus::cases())->mapWithKeys(fn (HousekeepingStatus $status): array => [$status->value => $status->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('housekeeping')
                    ->label('Housekeeping')
                    ->icon('heroicon-o-sparkles')
                    ->visible(fn (Resource $record): bool => $record->category->kind === ResourceKind::Place)
                    ->schema([
                        Select::make('status')
                            ->options(collect(HousekeepingStatus::cases())->mapWithKeys(fn (HousekeepingStatus $status): array => [$status->value => $status->label()])->all())
                            ->default(fn (Resource $record): ?string => $record->housekeeping_status?->value)
                            ->required(),
                    ])
                    ->action(fn (Resource $record, array $data) => app(HousekeepingService::class)->update($record, HousekeepingStatus::from($data['status']), auth()->id())),
            ])
            ->defaultSort('name')
            ->striped()
            ->emptyStateHeading('No resources configured')
            ->emptyStateDescription('Add places, assets or crew to make them bookable.')
            ->emptyStateIcon('heroicon-o-rectangle-group');
    }
}
