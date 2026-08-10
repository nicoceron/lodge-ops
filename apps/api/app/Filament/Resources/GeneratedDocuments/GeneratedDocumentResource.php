<?php

namespace App\Filament\Resources\GeneratedDocuments;

use App\Filament\Resources\GeneratedDocuments\Pages\ManageGeneratedDocuments;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\LodgeOpsPresentation;
use App\Models\GeneratedDocument;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class GeneratedDocumentResource extends TenantResource
{
    protected static ?string $model = GeneratedDocument::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Templates & Integrations';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'kind';

    protected static ?string $viewCapability = 'canManageConfiguration';

    protected static string $writeCapability = 'canManageConfiguration';

    protected static bool $canCreateRecords = false;

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Immutable generated document')->description('The checksum and private storage reference provide a permanent audit record.')->columns(2)->schema([
                TextEntry::make('kind')->badge(),
                TextEntry::make('status')->badge()->formatStateUsing(LodgeOpsPresentation::label(...))->color(fn ($state): string => LodgeOpsPresentation::statusColor($state)),
                TextEntry::make('reservation.confirmation_number')->label('Reservation')->placeholder('—'),
                TextEntry::make('guest.email')->label('Guest')->placeholder('—'),
                TextEntry::make('template.name')->label('Template')->placeholder('Version unavailable'),
                TextEntry::make('signed_at')->label('Signed')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone())->placeholder('Not signed'),
                TextEntry::make('storage_path')->label('Private storage path')->copyable()->columnSpanFull(),
                TextEntry::make('checksum')->copyable()->columnSpanFull(),
                KeyValueEntry::make('metadata')->columnSpanFull()->placeholder('No metadata'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Generated')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone())->sortable(),
                TextColumn::make('kind')->badge()->searchable(),
                TextColumn::make('status')->badge()->formatStateUsing(LodgeOpsPresentation::label(...))->color(fn ($state): string => LodgeOpsPresentation::statusColor($state)),
                TextColumn::make('reservation.confirmation_number')->label('Reservation')->searchable()->placeholder('—'),
                TextColumn::make('guest.email')->label('Guest')->searchable()->placeholder('—'),
                TextColumn::make('template.name')->label('Template')->placeholder('—'),
                TextColumn::make('checksum')->limit(12)->copyable(),
            ])
            ->filters([
                SelectFilter::make('kind')->options(fn (): array => GeneratedDocument::query()->distinct()->pluck('kind', 'kind')->all()),
                SelectFilter::make('status')->options(fn (): array => GeneratedDocument::query()->distinct()->pluck('status', 'status')->mapWithKeys(fn (string $value): array => [$value => LodgeOpsPresentation::label($value)])->all()),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No documents generated');
    }

    public static function getPages(): array
    {
        return ['index' => ManageGeneratedDocuments::route('/')];
    }
}
