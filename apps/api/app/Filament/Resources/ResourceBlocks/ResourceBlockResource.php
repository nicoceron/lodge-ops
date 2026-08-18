<?php

namespace App\Filament\Resources\ResourceBlocks;

use App\Enums\MembershipRole;
use App\Filament\Resources\ResourceBlocks\Pages\ManageResourceBlocks;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\Resource;
use App\Models\ResourceBlock;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ResourceBlockResource extends TenantResource
{
    protected static ?string $model = ResourceBlock::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-date-range';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Availability blocks';

    protected static ?string $viewCapability = 'canManageAvailability';

    protected static string $writeCapability = 'canManageAvailability';

    protected static string $deleteCapability = 'canManageAvailability';

    protected static ?string $propertyRelationship = 'resource';

    public static function getEloquentQuery(): Builder
    {
        $membership = app(TenantContext::class)->membership();

        return parent::getEloquentQuery()
            ->when($membership?->property_id, fn (Builder $query, string $propertyId): Builder => $query
                ->whereHas('resource', fn (Builder $resource): Builder => $resource->where('property_id', $propertyId)))
            ->when($membership?->role === MembershipRole::Guide, fn (Builder $query): Builder => $query
                ->whereHas('resource', fn (Builder $resource): Builder => $resource->where('user_id', auth()->id())));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Unavailable interval')->columns(2)->schema([
                Select::make('resource_id')->label('Resource')->options(static::resourceOptions(...))->searchable()->required(),
                TextInput::make('reason')->required()->maxLength(255),
                DateTimePicker::make('starts_at')->required()->seconds(false),
                DateTimePicker::make('ends_at')->required()->seconds(false)->after('starts_at'),
                Textarea::make('notes')->rows(3)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('resource.name')->label('Resource')->searchable()->weight('medium'),
                TextColumn::make('resource.category.name')->label('Category')->badge(),
                TextColumn::make('starts_at')->label('From')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->sortable(),
                TextColumn::make('ends_at')->label('Until')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->sortable(),
                TextColumn::make('reason')->searchable()->wrap(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('starts_at');
    }

    public static function getPages(): array
    {
        return ['index' => ManageResourceBlocks::route('/')];
    }

    private static function resourceOptions(): array
    {
        $membership = app(TenantContext::class)->membership();

        return Resource::query()
            ->where('is_active', true)
            ->when($membership?->property_id, fn (Builder $query, string $id): Builder => $query->where('property_id', $id))
            ->when($membership?->role === MembershipRole::Guide, fn (Builder $query): Builder => $query->where('user_id', auth()->id()))
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Resource $resource): array => [$resource->id => "{$resource->name} · {$resource->categoryName()}"])
            ->all();
    }
}
