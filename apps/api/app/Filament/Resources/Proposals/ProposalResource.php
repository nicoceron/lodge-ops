<?php

namespace App\Filament\Resources\Proposals;

use App\Enums\ProposalStatus;
use App\Filament\Resources\Proposals\Pages\CreateProposal;
use App\Filament\Resources\Proposals\Pages\EditProposal;
use App\Filament\Resources\Proposals\Pages\ListProposals;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Filament\Resources\Proposals\Schemas\ProposalForm;
use App\Filament\Resources\Proposals\Schemas\ProposalInfolist;
use App\Filament\Resources\Proposals\Tables\ProposalsTable;
use App\Filament\Resources\TenantResource;
use App\Models\Proposal;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ProposalResource extends TenantResource
{
    protected static ?string $model = Proposal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static string|\UnitEnum|null $navigationGroup = 'Commercial';

    protected static ?int $navigationSort = 5;

    protected static bool $canDeleteRecords = false;

    protected static ?string $viewCapability = 'canManageReservations';

    protected static string $writeCapability = 'canManageReservations';

    protected static ?string $propertyRelationship = 'reservation';

    public static function canEdit(Model $record): bool
    {
        if ($record->getAttribute('booking_quote_id') === null) {
            return false;
        }

        return parent::canEdit($record)
            && $record instanceof Proposal
            && $record->status === ProposalStatus::Draft;
    }

    public static function canRunWorkflow(Proposal $proposal): bool
    {
        return static::belongsToCurrentTenant($proposal) && static::canWrite() && $proposal->booking_quote_id !== null;
    }

    public static function form(Schema $schema): Schema
    {
        return ProposalForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProposalInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProposalsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProposals::route('/'),
            'create' => CreateProposal::route('/create'),
            'view' => ViewProposal::route('/{record}'),
            'edit' => EditProposal::route('/{record}/edit'),
        ];
    }
}
