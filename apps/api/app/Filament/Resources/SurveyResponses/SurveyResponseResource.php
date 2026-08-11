<?php

namespace App\Filament\Resources\SurveyResponses;

use App\Filament\Resources\SurveyResponses\Pages\ListSurveyResponses;
use App\Filament\Resources\SurveyResponses\Pages\ViewSurveyResponse;
use App\Filament\Resources\SurveyResponses\Schemas\SurveyResponseInfolist;
use App\Filament\Resources\SurveyResponses\Tables\SurveyResponsesTable;
use App\Filament\Resources\TenantResource;
use App\Models\Survey;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SurveyResponseResource extends TenantResource
{
    protected static ?string $model = Survey::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|\UnitEnum|null $navigationGroup = 'Guest experience';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'kind';

    protected static ?string $modelLabel = 'survey response';

    protected static ?string $pluralModelLabel = 'survey responses';

    protected static ?string $viewCapability = 'canManageGuests';

    protected static string $writeCapability = 'canManageGuests';

    protected static bool $canCreateRecords = false;

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    public static function getEloquentQuery(): Builder
    {
        $propertyId = app(TenantContext::class)->membership()?->property_id;

        return parent::getEloquentQuery()
            ->with(['reservation.property', 'reservation.program'])
            ->whereNotNull('responded_at')
            ->when($propertyId, fn (Builder $query) => $query->whereHas(
                'reservation',
                fn (Builder $reservation) => $reservation->where('property_id', $propertyId),
            ));
    }

    public static function canView(Model $record): bool
    {
        if (! $record instanceof Survey) {
            return false;
        }

        $record->loadMissing('reservation');

        return parent::canView($record)
            && $record->responded_at !== null
            && (app(TenantContext::class)->membership()?->property_id === null
                || $record->reservation?->property_id === app(TenantContext::class)->membership()?->property_id);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SurveyResponseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SurveyResponsesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSurveyResponses::route('/'),
            'view' => ViewSurveyResponse::route('/{record}'),
        ];
    }
}
