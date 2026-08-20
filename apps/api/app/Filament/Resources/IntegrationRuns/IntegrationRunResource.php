<?php

namespace App\Filament\Resources\IntegrationRuns;

use App\Filament\Resources\IntegrationRuns\Pages\ManageIntegrationRuns;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\IntegrationSyncRun;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IntegrationRunResource extends TenantResource
{
    protected static ?string $model = IntegrationSyncRun::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static string|\UnitEnum|null $navigationGroup = 'Templates & Integrations';

    protected static bool $canCreateRecords = false;

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    protected static ?string $viewCapability = 'canManageConfiguration';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->sortable(),
            TextColumn::make('connection.name')->label('Connection'), TextColumn::make('capability')->badge(),
            TextColumn::make('direction')->badge(), TextColumn::make('trigger'), TextColumn::make('status')->badge(),
            TextColumn::make('page_number')->label('Pages'), TextColumn::make('success_count')->label('Succeeded'),
            TextColumn::make('dead_letter_count')->label('Dead letters'), TextColumn::make('last_error')->limit(60)->placeholder('—'),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageIntegrationRuns::route('/')];
    }
}
