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
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class OperationalTaskForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Work item')
                ->description('A clear owner, deadline and context keep handoffs from falling through.')
                ->columns(2)
                ->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Select::make('property_id')
                        ->relationship('property', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('reservation_id')
                        ->label('Reservation')
                        ->relationship('reservation', 'confirmation_number')
                        ->searchable()
                        ->preload(),
                    Select::make('assignee_id')
                        ->label('Assignee')
                        ->relationship(
                            name: 'assignee',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn (Builder $query): Builder => $query->whereHas(
                                'memberships',
                                fn (Builder $memberships): Builder => $memberships
                                    ->where('tenant_id', app(TenantContext::class)->id())
                                    ->where('is_active', true),
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
                        ->timezone(LodgeOpsPresentation::timezone())
                        ->seconds(false),
                    Textarea::make('description')
                        ->rows(4)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
