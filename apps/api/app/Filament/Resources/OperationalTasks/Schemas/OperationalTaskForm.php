<?php

namespace App\Filament\Resources\OperationalTasks\Schemas;

use App\Enums\TaskStatus;
use App\Filament\Support\LodgeOpsPresentation;
use App\Support\Tenancy\TenantContext;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class OperationalTaskForm
{
    public static function configure(Schema $schema): Schema
    {
        $detailsAreReadOnly = fn (): bool => app(TenantContext::class)->membership()?->role?->canScheduleOperations() !== true;

        return $schema->components([
            Section::make('Work item')
                ->description('A clear owner, deadline and context keep handoffs from falling through.')
                ->columns(2)
                ->schema([
                    TextInput::make('title')
                        ->disabled($detailsAreReadOnly)
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Select::make('property_id')
                        ->disabled($detailsAreReadOnly)
                        ->options(LodgeOpsPresentation::propertyOptions(...))
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('reservation_id')
                        ->disabled($detailsAreReadOnly)
                        ->label('Reservation')
                        ->options(fn (Get $get): array => LodgeOpsPresentation::reservationOptions($get('property_id')))
                        ->searchable()
                        ->preload(),
                    Select::make('assignee_id')
                        ->disabled($detailsAreReadOnly)
                        ->label('Assignee')
                        ->relationship(
                            name: 'assignee',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn (Builder $query): Builder => $query->whereHas(
                                'memberships',
                                fn (Builder $memberships): Builder => $memberships
                                    ->where('tenant_id', app(TenantContext::class)->id())
                                    ->where('is_active', true)
                                    ->when(
                                        app(TenantContext::class)->membership()?->property_id,
                                        fn (Builder $memberships, string $propertyId): Builder => $memberships
                                            ->where(fn (Builder $scope): Builder => $scope
                                                ->whereNull('property_id')
                                                ->orWhere('property_id', $propertyId)),
                                    ),
                            ),
                        )
                        ->searchable()
                        ->preload(),
                    Select::make('status')
                        ->options(LodgeOpsPresentation::enumOptions(TaskStatus::cases()))
                        ->default(TaskStatus::Todo->value)
                        ->native(false)
                        ->required(),
                    Select::make('priority')
                        ->disabled($detailsAreReadOnly)
                        ->options([
                            'low' => 'Low',
                            'normal' => 'Normal',
                            'high' => 'High',
                            'urgent' => 'Urgent',
                        ])
                        ->default('normal')
                        ->native(false)
                        ->required(),
                    DateTimePicker::make('due_at')
                        ->disabled($detailsAreReadOnly)
                        ->timezone(LodgeOpsPresentation::timezone())
                        ->seconds(false),
                    Textarea::make('description')
                        ->disabled($detailsAreReadOnly)
                        ->rows(4)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
