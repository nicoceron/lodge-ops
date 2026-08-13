<?php

namespace App\Filament\Resources\Proposals\Schemas;

use App\Filament\Support\LodgeOpsPresentation;
use App\Models\Proposal;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProposalInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Proposal version')->columns(3)->schema([
                TextEntry::make('reference')->copyable(),
                TextEntry::make('version')->badge(),
                TextEntry::make('status')->badge()->formatStateUsing(LodgeOpsPresentation::label(...))->color(fn ($state): string => LodgeOpsPresentation::statusColor($state)),
                TextEntry::make('property.name')->label('Property'),
                TextEntry::make('primaryGuest.first_name')->label('Guest')->placeholder('Unassigned'),
                TextEntry::make('reservation.confirmation_number')->label('Converted reservation')->placeholder('Not converted')->copyable(),
                TextEntry::make('starts_at')->label('Arrival')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone()),
                TextEntry::make('ends_at')->label('Departure')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone()),
                TextEntry::make('total_minor')->label('Total')->money(fn (Proposal $record): string => $record->currency, divideBy: 100)->weight('bold'),
            ]),
            Section::make('Immutable pricing snapshot')->description('This JSON is frozen when the proposal is sent.')->schema([
                TextEntry::make('snapshot')
                    ->hiddenLabel()
                    ->state(fn (Proposal $record): string => json_encode($record->snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
                    ->copyable()
                    ->extraAttributes([
                        'style' => 'white-space: pre-wrap; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;',
                    ]),
            ]),
        ]);
    }
}
