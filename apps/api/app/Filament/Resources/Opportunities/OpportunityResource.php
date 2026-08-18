<?php

namespace App\Filament\Resources\Opportunities;

use App\Filament\Resources\Opportunities\Pages\CreateOpportunity;
use App\Filament\Resources\Opportunities\Pages\EditOpportunity;
use App\Filament\Resources\Opportunities\Pages\ListOpportunities;
use App\Filament\Resources\Opportunities\Pages\ViewOpportunity;
use App\Filament\Resources\Opportunities\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\Guest;
use App\Models\Opportunity;
use App\Models\Proposal;
use App\Services\OpportunityService;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OpportunityResource extends TenantResource
{
    protected static ?string $model = Opportunity::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static string|\UnitEnum|null $navigationGroup = 'Sales & CRM';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $viewCapability = 'canManageSales';

    protected static string $writeCapability = 'canManageSales';

    protected static bool $canDeleteRecords = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Opportunity')->columns(2)->schema([
                TextInput::make('title')->required()->maxLength(200)->columnSpanFull(),
                Select::make('property_id')->label('Property')->options(InnPresentation::propertyOptions(...))->required()->searchable()->preload(),
                Select::make('owner_id')->label('Owner')->relationship('owner', 'name')->searchable()->preload()->default(auth()->id()),
                Select::make('guest_id')->label('Guest')->relationship('guest', 'email')->getOptionLabelFromRecordUsing(fn (Guest $record): string => trim("{$record->first_name} {$record->last_name}").($record->email ? " · {$record->email}" : ''))->searchable(['first_name', 'last_name', 'email'])->preload(),
                Select::make('organization_id')->label('Organization')->relationship('organization', 'name')->searchable()->preload(),
                TextInput::make('source')->maxLength(50),
                DatePicker::make('expected_close_on')->label('Expected close'),
                TextInput::make('currency')->required()->length(3)->default(fn (): string => app(TenantContext::class)->tenant()->currency)->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
                TextInput::make('value_minor')->label('Estimated value · minor units')->numeric()->minValue(0)->default(0)->required(),
                TextInput::make('stage')->disabled()->dehydrated(false)->visibleOn('edit'),
                Textarea::make('lost_reason')->label('Lost reason')->disabled()->dehydrated(false)->visible(fn (?Opportunity $record): bool => $record?->stage === 'lost')->columnSpanFull(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Pipeline summary')->columns(2)->schema([
                TextEntry::make('title')->columnSpanFull()->weight('bold'),
                TextEntry::make('stage')->badge()->formatStateUsing(InnPresentation::label(...))->color(fn ($state): string => self::stageColor($state)),
                TextEntry::make('value_minor')->label('Estimated value')->money(fn (Opportunity $record): string => $record->currency, divideBy: 100),
                TextEntry::make('property.name')->label('Property'),
                TextEntry::make('owner.name')->label('Owner')->placeholder('Unassigned'),
                TextEntry::make('guest.email')->label('Guest')->placeholder('—'),
                TextEntry::make('organization.name')->label('Organization')->placeholder('—'),
                TextEntry::make('proposal.reference')->label('Proposal')->placeholder('Not attached'),
                TextEntry::make('expected_close_on')->label('Expected close')->date()->placeholder('—'),
                TextEntry::make('source')->placeholder('—'),
                TextEntry::make('lost_reason')->label('Lost reason')->placeholder('—')->visible(fn (Opportunity $record): bool => $record->stage === 'lost')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->weight('medium')->limit(45),
                TextColumn::make('stage')->badge()->formatStateUsing(InnPresentation::label(...))->color(fn ($state): string => self::stageColor($state))->sortable(),
                TextColumn::make('value_minor')->label('Value')->money(fn (Opportunity $record): string => $record->currency, divideBy: 100)->sortable(),
                TextColumn::make('organization.name')->label('Organization')->searchable()->placeholder('—'),
                TextColumn::make('guest.email')->label('Guest')->searchable()->placeholder('—'),
                TextColumn::make('owner.name')->label('Owner')->placeholder('—'),
                TextColumn::make('expected_close_on')->label('Expected close')->date()->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('stage')->options(['inquiry' => 'Inquiry', 'qualified' => 'Qualified', 'proposal' => 'Proposal', 'won' => 'Won', 'lost' => 'Lost'])->multiple(),
                SelectFilter::make('property_id')->label('Property')->options(InnPresentation::propertyOptions()),
                SelectFilter::make('owner_id')->label('Owner')->relationship('owner', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ActionGroup::make(self::workflowActions()),
            ])
            ->defaultSort('expected_close_on')
            ->emptyStateHeading('No opportunities in the pipeline');
    }

    /** @return array<Action> */
    public static function workflowActions(): array
    {
        return [
            Action::make('qualify')->icon('heroicon-o-check-badge')->color('info')->authorize('update')->visible(fn (Opportunity $record): bool => $record->stage === 'inquiry' && self::canEdit($record))->action(fn (Opportunity $record) => self::transition($record, 'qualified')),
            Action::make('attach_proposal')->label('Attach proposal')->icon('heroicon-o-document-text')->schema([
                Select::make('proposal_id')->label('Proposal')->options(fn (): array => Proposal::query()->orderByDesc('created_at')->get()->mapWithKeys(fn (Proposal $proposal): array => [$proposal->id => "{$proposal->reference} · v{$proposal->version}"])->all())->required()->searchable(),
            ])->authorize('update')->visible(fn (Opportunity $record): bool => in_array($record->stage, ['inquiry', 'qualified', 'proposal'], true) && self::canEdit($record))->action(function (Opportunity $record, array $data): void {
                app(OpportunityService::class)->attachProposal($record, Proposal::query()->findOrFail($data['proposal_id']));
                Notification::make()->success()->title('Proposal attached')->send();
            }),
            Action::make('mark_won')->label('Mark won')->icon('heroicon-o-trophy')->color('success')->authorize('update')->requiresConfirmation()->visible(fn (Opportunity $record): bool => $record->stage === 'proposal' && self::canEdit($record))->action(fn (Opportunity $record) => self::transition($record, 'won')),
            Action::make('mark_lost')->label('Mark lost')->icon('heroicon-o-x-circle')->color('danger')->schema([
                Textarea::make('lost_reason')->label('Reason')->required()->maxLength(2000),
            ])->authorize('update')->visible(fn (Opportunity $record): bool => in_array($record->stage, ['inquiry', 'qualified', 'proposal'], true) && self::canEdit($record))->action(fn (Opportunity $record, array $data) => self::transition($record, 'lost', $data['lost_reason'])),
        ];
    }

    public static function getRelations(): array
    {
        return [ActivitiesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOpportunities::route('/'),
            'create' => CreateOpportunity::route('/create'),
            'view' => ViewOpportunity::route('/{record}'),
            'edit' => EditOpportunity::route('/{record}/edit'),
        ];
    }

    private static function transition(Opportunity $record, string $stage, ?string $reason = null): void
    {
        app(OpportunityService::class)->transition($record, $stage, $reason);
        Notification::make()->success()->title('Opportunity moved to '.InnPresentation::label($stage))->send();
    }

    private static function stageColor(?string $stage): string
    {
        return match ($stage) {
            'won' => 'success',
            'lost' => 'danger',
            'proposal' => 'warning',
            'qualified' => 'info',
            default => 'gray',
        };
    }
}
