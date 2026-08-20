<?php

namespace App\Filament\Resources\Communications;

use App\Filament\Resources\Communications\Pages\ManageCommunications;
use App\Filament\Resources\TenantResource;
use App\Models\Communication;
use App\Models\User;
use App\Services\Communications\CommunicationOperationsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CommunicationResource extends TenantResource
{
    protected static ?string $model = Communication::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static string|\UnitEnum|null $navigationGroup = 'Templates & Integrations';

    protected static ?int $navigationSort = 25;

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
            Section::make('Immutable communication snapshot')->columns(2)->schema([
                TextEntry::make('status')->badge(),
                TextEntry::make('purpose')->badge(),
                TextEntry::make('subject')->columnSpanFull(),
                TextEntry::make('body')->columnSpanFull(),
                TextEntry::make('content_checksum')->copyable()->columnSpanFull(),
                TextEntry::make('accepted_at')->dateTime()->placeholder('Not accepted'),
                TextEntry::make('delivered_at')->dateTime()->placeholder('No authenticated delivery event'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->since()->sortable(),
            TextColumn::make('subject')->limit(42)->searchable(),
            TextColumn::make('purpose')->badge(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('accepted_at')->label('Accepted')->since()->placeholder('—'),
            TextColumn::make('delivered_at')->label('Delivered event')->since()->placeholder('—'),
        ])->filters([
            SelectFilter::make('status')->options(array_combine($statuses = ['queued', 'sending', 'provider_accepted', 'sent', 'delivered', 'delayed', 'soft_bounced', 'hard_bounced', 'complained', 'suppressed', 'rejected', 'failed', 'retry_pending', 'outcome_uncertain', 'reconciliation_required'], $statuses)),
        ])->recordActions([
            ViewAction::make()->label('Preview'),
            Action::make('retry')->visible(fn (Communication $record): bool => in_array($record->status, ['failed', 'retry_pending', 'outcome_uncertain'], true))
                ->requiresConfirmation()->action(function (Communication $record): void {
                    app(CommunicationOperationsService::class)->retry(User::query()->findOrFail(auth()->id()), $record);
                    Notification::make()->success()->title('Delivery retry queued with the original provider identity')->send();
                }),
            Action::make('new_resend')->label('New resend')->requiresConfirmation()->action(function (Communication $record): void {
                app(CommunicationOperationsService::class)->newResend(User::query()->findOrFail(auth()->id()), $record);
                Notification::make()->success()->title('New audited resend created')->send();
            }),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageCommunications::route('/')];
    }
}
