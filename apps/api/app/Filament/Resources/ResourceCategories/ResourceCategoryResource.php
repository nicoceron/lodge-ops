<?php

namespace App\Filament\Resources\ResourceCategories;

use App\Enums\ResourceKind;
use App\Filament\Resources\ResourceCategories\Pages\ManageResourceCategories;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\ResourceCategory;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ResourceCategoryResource extends TenantResource
{
    protected static ?string $model = ResourceCategory::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\UnitEnum|null $navigationGroup = 'Setup';

    protected static ?int $navigationSort = 18;

    protected static string $writeCapability = 'canManageConfiguration';

    protected static ?string $viewCapability = 'canManageConfiguration';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Catalog category')
                ->description('Places, assets and crew are platform kinds. Names like Cabin or Guide belong to this property.')
                ->columns(2)
                ->schema([
                    Select::make('property_id')
                        ->options(InnPresentation::propertyOptions(...))
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('kind')
                        ->options(InnPresentation::enumOptions(ResourceKind::cases()))
                        ->required(),
                    TextInput::make('name')->required()->maxLength(80),
                    TextInput::make('slug')->maxLength(40)->helperText('Leave blank to generate from the name.'),
                    Toggle::make('counts_as_stay')
                        ->label('Counts as stay inventory')
                        ->helperText('Used when a program requires accommodation covering the full stay.')
                        ->default(false),
                    TextInput::make('default_capacity')->integer()->minValue(1)->default(1)->required(),
                    TextInput::make('sort_order')->integer()->minValue(0)->default(0),
                    Toggle::make('is_active')->default(true)->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('kind')->badge()->formatStateUsing(InnPresentation::label(...)),
                TextColumn::make('property.name')->label('Property')->sortable(),
                IconColumn::make('counts_as_stay')->label('Stay')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('sort_order')->numeric()->sortable(),
            ])
            ->filters([
                SelectFilter::make('kind')->options(InnPresentation::enumOptions(ResourceKind::cases())),
                SelectFilter::make('property')->relationship('property', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('sort_order')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageResourceCategories::route('/'),
        ];
    }
}
