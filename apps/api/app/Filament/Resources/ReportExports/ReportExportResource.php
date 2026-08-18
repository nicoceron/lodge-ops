<?php

namespace App\Filament\Resources\ReportExports;

use App\Enums\ReportExportKind;
use App\Enums\ReportExportStatus;
use App\Filament\Resources\ReportExports\Pages\ManageReportExports;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Reports\ReportArtifactStore;
use App\Services\Reports\RetryReportExport;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReportExportResource extends TenantResource
{
    protected static ?string $model = ReportExport::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 50;

    protected static ?string $viewCapability = null;

    protected static string $writeCapability = 'canManageMoney';

    protected static bool $canCreateRecords = false;

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $role = app(TenantContext::class)->membership()?->role;
        $finance = [ReportExportKind::Revenue->value, ReportExportKind::PaymentsDepositsRefunds->value, ReportExportKind::CostsMarginCommissions->value];
        if ($role?->canViewFinance() !== true) {
            $query->whereNotIn('kind', $finance);
        } elseif (! $role->canManageOperations()) {
            $query->whereIn('kind', $finance);
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Export job')->columns(2)->schema([
                TextEntry::make('kind')->badge()->formatStateUsing(fn ($state): string => InnPresentation::label($state)),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state): string => InnPresentation::label($state))->color(fn ($state): string => InnPresentation::statusColor($state)),
                TextEntry::make('requestedBy.name')->label('Requested by'),
                TextEntry::make('row_count')->label('Rows')->numeric(),
                TextEntry::make('created_at')->label('Requested')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone()),
                TextEntry::make('completed_at')->label('Completed')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->placeholder('Pending'),
                TextEntry::make('file_name')->label('Download name')->placeholder('Not ready'),
                TextEntry::make('format')->badge(),
                TextEntry::make('size_bytes')->label('Size')->numeric()->placeholder('Not ready'),
                TextEntry::make('checksum')->limit(16)->copyable()->placeholder('Not ready'),
                TextEntry::make('expires_at')->label('Expires')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->placeholder('Not ready'),
                TextEntry::make('last_error')->label('Failure')->placeholder('None')->columnSpanFull(),
                KeyValueEntry::make('filters')->columnSpanFull()->placeholder('No filters'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Requested')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->sortable(),
                TextColumn::make('kind')->badge()->formatStateUsing(fn ($state): string => InnPresentation::label($state))->searchable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state): string => InnPresentation::label($state))->color(fn ($state): string => InnPresentation::statusColor($state)),
                TextColumn::make('requestedBy.name')->label('Requested by')->searchable(),
                TextColumn::make('row_count')->label('Rows')->numeric()->sortable(),
                TextColumn::make('completed_at')->label('Completed')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->placeholder('—'),
            ])
            ->filters([SelectFilter::make('status')->options(InnPresentation::enumOptions(ReportExportStatus::cases()))])
            ->recordActions([
                ViewAction::make(),
                Action::make('download')->label('Download')->icon('heroicon-o-arrow-down-tray')->authorize('download')->visible(fn (ReportExport $record): bool => $record->status === ReportExportStatus::Completed && $record->purged_at === null)->url(fn (ReportExport $record): string => route('filament.admin.report-exports.download', ['tenant' => Filament::getTenant(), 'reportExport' => $record])),
                Action::make('retry')->label('Retry')->icon('heroicon-o-arrow-path')->authorize('retry')
                    ->visible(fn (ReportExport $record): bool => $record->status === ReportExportStatus::Failed)
                    ->action(function (ReportExport $record): void {
                        app(RetryReportExport::class)->handle(User::query()->findOrFail(auth()->id()), $record);
                        Notification::make()->success()->title('Report export retry queued')->send();
                    }),
                Action::make('purge')->label('Purge expired object')->icon('heroicon-o-trash')->color('danger')->authorize('purge')->requiresConfirmation()
                    ->visible(fn (ReportExport $record): bool => $record->purged_at === null && $record->expires_at?->isPast() === true)
                    ->action(function (ReportExport $record): void {
                        if ($record->storage_disk && $record->storage_path) {
                            app(ReportArtifactStore::class)->delete($record->storage_disk, $record->storage_path);
                        }
                        $record->forceFill(['purged_at' => now()])->save();
                        Notification::make()->success()->title('Expired report object purged; audit row retained')->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No report exports requested');
    }

    public static function getPages(): array
    {
        return ['index' => ManageReportExports::route('/')];
    }
}
