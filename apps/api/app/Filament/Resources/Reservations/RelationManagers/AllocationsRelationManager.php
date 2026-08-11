<?php

namespace App\Filament\Resources\Reservations\RelationManagers;

use App\Enums\AllocationStatus;
use App\Enums\ReservationStatus;
use App\Enums\ResourceType;
use App\Filament\Support\LodgeOpsPresentation;
use App\Models\Allocation;
use App\Models\Resource;
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
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class AllocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'allocations';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('resource_id')
                ->label('Resource')
                ->options(fn (): array => Resource::query()
                    ->where('property_id', $this->getOwnerRecord()->property_id)
                    ->where('is_active', true)->orderBy('name')->get()
                    ->mapWithKeys(fn (Resource $resource): array => [
                        $resource->id => "{$resource->name} · {$resource->type->value}",
                    ])->all())
                ->searchable(),
            Select::make('service_occurrence_id')
                ->label('Scheduled activity')
                ->options(fn (): array => ServiceOccurrence::query()->with('program')
                    ->where('property_id', $this->getOwnerRecord()->property_id)
                    ->where('is_cancelled', false)->orderBy('starts_at')->get()
                    ->mapWithKeys(fn (ServiceOccurrence $occurrence): array => [
                        $occurrence->id => "{$occurrence->program->name} · {$occurrence->starts_at->format('M j, Y H:i')}",
                    ])->all())
                ->searchable(),
            DateTimePicker::make('starts_at')->required()->seconds(false),
            DateTimePicker::make('ends_at')->required()->seconds(false)->after('starts_at'),
            TextInput::make('quantity')->integer()->minValue(1)->default(1)->required(),
            Select::make('status')
                ->options(LodgeOpsPresentation::enumOptions(AllocationStatus::cases()))
                ->disabled(fn (): bool => $this->getOwnerRecord()->status === ReservationStatus::Confirmed)
                ->dehydrated(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('resource.name')->label('Resource')->placeholder('—')->searchable(),
                TextColumn::make('serviceOccurrence.program.name')->label('Activity')->placeholder('—'),
                TextColumn::make('starts_at')->label('From')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone())->sortable(),
                TextColumn::make('ends_at')->label('Until')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone())->sortable(),
                TextColumn::make('quantity')->numeric(),
                TextColumn::make('status')->badge()->formatStateUsing(LodgeOpsPresentation::label(...)),
            ])
            ->headerActions([
                Action::make('suggestResource')
                    ->label('Suggest resource')
                    ->icon('heroicon-o-sparkles')
                    ->authorize(fn (): bool => Gate::allows('viewAny', Resource::class))
                    ->schema([
                        Select::make('type')
                            ->options(LodgeOpsPresentation::enumOptions(ResourceType::cases()))
                            ->required(),
                        DateTimePicker::make('starts_at')
                            ->default(fn () => $this->getOwnerRecord()->starts_at)
                            ->required()
                            ->seconds(false),
                        DateTimePicker::make('ends_at')
                            ->default(fn () => $this->getOwnerRecord()->ends_at)
                            ->required()
                            ->seconds(false)
                            ->after('starts_at'),
                        TextInput::make('quantity')->integer()->minValue(1)->default(1)->required(),
                    ])
                    ->action(function (array $data): void {
                        $suggestions = app(ResourceSuggestionService::class)->suggest(
                            ResourceType::from($data['type']),
                            CarbonImmutable::parse($data['starts_at']),
                            CarbonImmutable::parse($data['ends_at']),
                            quantity: (int) $data['quantity'],
                            propertyId: $this->getOwnerRecord()->property_id,
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
                CreateAction::make()->using(fn (array $data): Allocation => app(AllocationWorkflowService::class)
                    ->create($this->getOwnerRecord(), $data)),
            ])
            ->recordActions([
                EditAction::make()->using(fn (Allocation $record, array $data): Allocation => app(AllocationWorkflowService::class)
                    ->update($this->getOwnerRecord(), $record, $data)),
                Action::make('release')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->visible(fn (Allocation $record): bool => $record->status !== AllocationStatus::Released)
                    ->action(fn (Allocation $record) => app(AllocationWorkflowService::class)
                        ->release($this->getOwnerRecord(), $record)),
            ])
            ->defaultSort('starts_at');
    }
}
