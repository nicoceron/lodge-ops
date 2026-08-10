<?php

namespace App\Filament\Resources\DocumentTemplates;

use App\Filament\Resources\DocumentTemplates\Pages\CreateDocumentTemplate;
use App\Filament\Resources\DocumentTemplates\Pages\ListDocumentTemplates;
use App\Filament\Resources\DocumentTemplates\Pages\ViewDocumentTemplate;
use App\Filament\Resources\DocumentTemplates\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\TenantResource;
use App\Models\DocumentTemplate;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
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

class DocumentTemplateResource extends TenantResource
{
    protected static ?string $model = DocumentTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-duplicate';

    protected static string|\UnitEnum|null $navigationGroup = 'Templates & Integrations';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $viewCapability = 'canManageConfiguration';

    protected static string $writeCapability = 'canManageConfiguration';

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('New document template version')->description('Creating a version retires the previous active version of the same kind.')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(160),
                TextInput::make('kind')->required()->maxLength(50)->alphaDash(),
                KeyValue::make('definition')->label('Template definition')->required()->columnSpanFull(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Versioned template')->columns(2)->schema([
                TextEntry::make('name')->weight('bold'),
                TextEntry::make('kind')->badge(),
                TextEntry::make('version')->badge(),
                IconEntry::make('is_active')->label('Active')->boolean(),
                KeyValueEntry::make('definition')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->weight('medium'),
                TextColumn::make('kind')->badge()->searchable(),
                TextColumn::make('version')->prefix('v')->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('created_at')->label('Created')->dateTime('M j, Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('kind')->options(fn (): array => DocumentTemplate::query()->distinct()->pluck('kind', 'kind')->all()),
                TernaryFilter::make('is_active'),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No document templates');
    }

    public static function getRelations(): array
    {
        return [DocumentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocumentTemplates::route('/'),
            'create' => CreateDocumentTemplate::route('/create'),
            'view' => ViewDocumentTemplate::route('/{record}'),
        ];
    }
}
