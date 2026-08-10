<?php

namespace App\Filament\Resources\Organizations;

use App\Filament\Resources\Organizations\Pages\ManageOrganizations;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\LodgeOpsPresentation;
use App\Models\Organization;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class OrganizationResource extends TenantResource
{
    protected static ?string $model = Organization::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected static string|\UnitEnum|null $navigationGroup = 'Sales & CRM';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $viewCapability = 'canManageSales';

    protected static string $writeCapability = 'canManageSales';

    protected static bool $canDeleteRecords = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Organization profile')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(160),
                Select::make('type')->options(['agency' => 'Agency', 'company' => 'Company', 'household' => 'Household'])->required()->default('agency'),
                TextInput::make('email')->email()->maxLength(255),
                TextInput::make('phone')->tel()->maxLength(40),
                TextInput::make('commission_basis_points')->label('Commission rate')->numeric()->minValue(0)->maxValue(10000)->suffix('basis points')->default(0)->required(),
                Toggle::make('is_active')->default(true)->required(),
                KeyValue::make('metadata')->label('Additional details')->columnSpanFull(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Organization')->columns(2)->schema([
                TextEntry::make('name'),
                TextEntry::make('type')->badge()->formatStateUsing(LodgeOpsPresentation::label(...)),
                TextEntry::make('email')->placeholder('—'),
                TextEntry::make('phone')->placeholder('—'),
                TextEntry::make('commission_basis_points')->label('Commission')->formatStateUsing(fn (int $state): string => number_format($state / 100, 2).'%'),
                IconEntry::make('is_active')->boolean(),
                KeyValueEntry::make('metadata')->columnSpanFull()->placeholder('No additional details'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('medium'),
                TextColumn::make('type')->badge()->formatStateUsing(LodgeOpsPresentation::label(...))->sortable(),
                TextColumn::make('email')->searchable()->placeholder('—'),
                TextColumn::make('commission_basis_points')->label('Commission')->formatStateUsing(fn (int $state): string => number_format($state / 100, 2).'%')->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')->options(['agency' => 'Agency', 'company' => 'Company', 'household' => 'Household']),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->defaultSort('name')
            ->emptyStateHeading('No sales organizations yet')
            ->emptyStateDescription('Add agencies, companies, or households to connect them to opportunities.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageOrganizations::route('/')];
    }
}
