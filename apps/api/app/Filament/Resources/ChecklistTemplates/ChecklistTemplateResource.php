<?php

namespace App\Filament\Resources\ChecklistTemplates;

use App\Filament\Resources\ChecklistTemplates\Pages\ManageChecklistTemplates;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\ChecklistTemplate;
use App\Models\Program;
use App\Services\ChecklistWorkflowService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChecklistTemplateResource extends TenantResource
{
    protected static ?string $model = ChecklistTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-list-bullet';

    protected static string|\UnitEnum|null $navigationGroup = 'Setup';

    protected static ?string $navigationLabel = 'Checklist templates';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $viewCapability = 'canManageConfiguration';

    protected static string $writeCapability = 'canManageConfiguration';

    protected static string $deleteCapability = 'canManageConfiguration';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Versioned operational checklist')->columns(2)->schema([
                Select::make('property_id')->options(InnPresentation::propertyOptions(...))->searchable()->preload()->live()->required(),
                Select::make('program_id')->label('Program scope')->helperText('Optional; leave blank for every reservation at this property.')
                    ->options(fn (Get $get): array => Program::query()->when($get('property_id'), fn ($query, string $id) => $query->where('property_id', $id))
                        ->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                TextInput::make('name')->required()->maxLength(255),
                Select::make('role')->options([
                    'operations' => 'Operations', 'guide' => 'Guide', 'kitchen' => 'Kitchen', 'housekeeping' => 'Housekeeping',
                ])->required(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('property.name')->label('Property'),
            TextColumn::make('program.name')->label('Program')->placeholder('All programs'),
            TextColumn::make('role')->badge(),
            TextColumn::make('latest_version')->label('Version')->badge(),
            TextColumn::make('state')->badge(),
        ])->recordActions([
            Action::make('publishVersion')->label('Publish new version')->icon('heroicon-o-paper-airplane')
                ->visible(fn (ChecklistTemplate $record): bool => $record->state !== 'retired')
                ->schema([
                    Repeater::make('items')->label('Ordered tasks')->schema([
                        TextInput::make('title')->required()->maxLength(255)->columnSpan(2),
                        Textarea::make('description')->rows(2)->columnSpan(2),
                        Select::make('priority')->options(['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'])->default('normal')->required(),
                        TextInput::make('due_offset_minutes')->label('Minutes from arrival')->integer()->default(0)->required(),
                    ])->columns(2)->minItems(1)->defaultItems(1)->reorderable()->required(),
                ])->action(function (ChecklistTemplate $record, array $data): void {
                    $version = app(ChecklistWorkflowService::class)->publish($record, $data['items'], auth()->id());
                    Notification::make()->success()->title("Checklist version {$version->version} published")->body('Existing reservation tasks remain frozen until an operator regenerates them.')->send();
                }),
            Action::make('retire')->color('danger')->icon('heroicon-o-archive-box')->requiresConfirmation()
                ->visible(fn (ChecklistTemplate $record): bool => $record->state !== 'retired')
                ->action(function (ChecklistTemplate $record): void {
                    app(ChecklistWorkflowService::class)->retire($record);
                    Notification::make()->success()->title('Checklist retired')->send();
                }),
            EditAction::make()->visible(fn (ChecklistTemplate $record): bool => $record->latest_version === 0),
            DeleteAction::make()->visible(fn (ChecklistTemplate $record): bool => $record->latest_version === 0),
        ])->defaultSort('name')->striped();
    }

    public static function getPages(): array
    {
        return ['index' => ManageChecklistTemplates::route('/')];
    }
}
