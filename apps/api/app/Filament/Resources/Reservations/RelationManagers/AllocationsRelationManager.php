<?php

namespace App\Filament\Resources\Reservations\RelationManagers;

use App\Enums\AllocationStatus;
use App\Enums\ReservationStatus;
use App\Filament\Support\InnPresentation;
use App\Models\Allocation;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\ResourceCategory;
use App\Models\ServiceOccurrence;
use App\Services\AllocationWorkflowService;
use App\Services\ResourceSuggestionService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use LogicException;

class AllocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'allocations';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('requested_category_id')
                ->label('Requested category')
                ->helperText('Reserve a category now; assign the exact instance now or later.')
                ->options(fn (): array => ResourceCategory::query()
                    ->where('property_id', $this->ownerReservation()->property_id)
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get()
                    ->mapWithKeys(fn (ResourceCategory $category): array => [
                        $category->id => "{$category->name} · {$category->kind->singular()}",
                    ])->all())
                ->live()
                ->searchable(),
            Select::make('resource_id')
                ->label('Assigned instance')
                ->helperText('Optional until operations assigns a specific place, asset, or crew member.')
                ->options(fn (Get $get): array => Resource::query()
                    ->with('category')
                    ->where('property_id', $this->ownerReservation()->property_id)
                    ->where('is_active', true)
                    ->when($get('requested_category_id'), fn ($query, $categoryId) => $query->where('category_id', $categoryId))
                    ->orderBy('name')
                    ->get()
                    ->mapWithKeys(fn (Resource $resource): array => [
                        $resource->id => "{$resource->name} · {$resource->categoryName()} · {$resource->kind()->singular()}",
                    ])->all())
                ->searchable(),
            Select::make('service_occurrence_id')
                ->label('Scheduled activity')
                ->options(fn (): array => ServiceOccurrence::query()->with('program')
                    ->where('property_id', $this->ownerReservation()->property_id)
                    ->where('is_cancelled', false)->orderBy('starts_at')->get()
                    ->mapWithKeys(fn (ServiceOccurrence $occurrence): array => [
                        $occurrence->id => "{$occurrence->program->name} · {$occurrence->starts_at->format('M j, Y H:i')}",
                    ])->all())
                ->searchable(),
            DateTimePicker::make('starts_at')->default(fn () => $this->ownerReservation()->starts_at)->required()->seconds(false),
            DateTimePicker::make('ends_at')->default(fn () => $this->ownerReservation()->ends_at)->required()->seconds(false)->after('starts_at'),
            TextInput::make('quantity')->integer()->minValue(1)->default(1)->required(),
            Select::make('status')
                ->options(InnPresentation::enumOptions(AllocationStatus::cases()))
                ->disabled(fn (): bool => $this->ownerReservation()->status === ReservationStatus::Confirmed)
                ->dehydrated(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('requestedCategory.name')->label('Requested')->badge()->placeholder('Activity only'),
                TextColumn::make('requestedCategory.kind')
                    ->label('Kind')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) && method_exists($state, 'singular') ? $state->singular() : (string) $state)
                    ->placeholder('—'),
                TextColumn::make('resource.name')->label('Assigned instance')->placeholder('Pending assignment')->searchable(),
                TextColumn::make('serviceOccurrence.program.name')->label('Activity')->placeholder('—'),
                TextColumn::make('starts_at')->label('From')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->sortable(),
                TextColumn::make('ends_at')->label('Until')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->sortable(),
                TextColumn::make('quantity')->numeric(),
                TextColumn::make('status')->badge()->formatStateUsing(InnPresentation::label(...)),
            ])
            ->headerActions([
                Action::make('suggestResource')
                    ->label('Suggest resource')
                    ->icon('heroicon-o-sparkles')
                    ->authorize(fn (): bool => Gate::allows('viewAny', Resource::class))
                    ->schema([
                        Select::make('category_id')
                            ->label('Category')
                            ->options(fn (): array => ResourceCategory::query()
                                ->where('property_id', $this->ownerReservation()->property_id)
                                ->where('is_active', true)
                                ->orderBy('sort_order')
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (ResourceCategory $category): array => [
                                    $category->id => "{$category->name} · {$category->kind->singular()}",
                                ])->all())
                            ->required(),
                        DateTimePicker::make('starts_at')
                            ->default(fn () => $this->ownerReservation()->starts_at)
                            ->required()
                            ->seconds(false),
                        DateTimePicker::make('ends_at')
                            ->default(fn () => $this->ownerReservation()->ends_at)
                            ->required()
                            ->seconds(false)
                            ->after('starts_at'),
                        TextInput::make('quantity')->integer()->minValue(1)->default(1)->required(),
                    ])
                    ->action(function (array $data): void {
                        $category = ResourceCategory::query()->findOrFail($data['category_id']);
                        $suggestions = app(ResourceSuggestionService::class)->suggest(
                            $category,
                            CarbonImmutable::parse($data['starts_at']),
                            CarbonImmutable::parse($data['ends_at']),
                            quantity: (int) $data['quantity'],
                            propertyId: $this->ownerReservation()->property_id,
                        );

                        if ($suggestions->isEmpty()) {
                            Notification::make()
                                ->warning()
                                ->title('No available resource matches this window')
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Recommended: '.$suggestions->take(3)->pluck('name')->implode(', '))
                            ->body($suggestions->take(3)->map(
                                fn (array $suggestion): string => $suggestion['name'].' — '.implode('; ', $suggestion['reasons']),
                            )->implode("\n"))
                            ->send();
                    }),
                CreateAction::make()
                    ->visible(fn (): bool => in_array($this->ownerReservation()->status, [ReservationStatus::Draft, ReservationStatus::Hold], true))
                    ->using(fn (array $data): Allocation => app(AllocationWorkflowService::class)
                        ->create($this->ownerReservation(), $data, auth()->id(), 'Allocation created through the reservation workbench.')),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => in_array($this->ownerReservation()->status, [ReservationStatus::Draft, ReservationStatus::Hold], true))
                    ->using(fn (Allocation $record, array $data): Allocation => app(AllocationWorkflowService::class)
                        ->update($this->ownerReservation(), $record, $data, auth()->id(), 'Allocation updated through the reservation workbench.')),
                Action::make('release')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->visible(fn (Allocation $record): bool => $record->status !== AllocationStatus::Released
                        && in_array($this->ownerReservation()->status, [ReservationStatus::Draft, ReservationStatus::Hold], true))
                    ->action(fn (Allocation $record) => app(AllocationWorkflowService::class)
                        ->release($this->ownerReservation(), $record, auth()->id(), 'Allocation released through the reservation workbench.')),
            ])
            ->defaultSort('starts_at');
    }

    private function ownerReservation(): Reservation
    {
        $record = $this->getOwnerRecord();
        if (! $record instanceof Reservation) {
            throw new LogicException('The allocation manager must be mounted on a reservation.');
        }

        return $record;
    }
}
