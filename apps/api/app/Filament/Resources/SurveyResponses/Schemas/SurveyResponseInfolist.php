<?php

namespace App\Filament\Resources\SurveyResponses\Schemas;

use App\Filament\Support\LodgeOpsPresentation;
use App\Models\Survey;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SurveyResponseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Response summary')
                ->columns(3)
                ->schema([
                    TextEntry::make('reservation.confirmation_number')->label('Reservation')->placeholder('Unknown'),
                    TextEntry::make('reservation.property.name')->label('Property')->placeholder('Unknown'),
                    TextEntry::make('reservation.program.name')->label('Program')->placeholder('No program'),
                    TextEntry::make('responded_at')
                        ->label('Response date')
                        ->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone()),
                    TextEntry::make('score')
                        ->label('Rating')
                        ->badge()
                        ->suffix('/ 5')
                        ->color(fn (?int $state): string => match (true) {
                            $state >= 4 => 'success',
                            $state === 3 => 'warning',
                            default => 'danger',
                        }),
                    TextEntry::make('kind')->label('Survey type')->formatStateUsing(LodgeOpsPresentation::label(...)),
                ]),
            Section::make('Guest comments')
                ->schema([
                    TextEntry::make('comment')
                        ->label('Comments')
                        ->state(fn (Survey $record): ?string => data_get($record->answers, 'comment'))
                        ->placeholder('No comments provided.')
                        ->columnSpanFull(),
                    TextEntry::make('guide_rating')
                        ->label('Guide rating')
                        ->state(fn (Survey $record): ?string => ($rating = data_get($record->answers, 'guide_rating')) === null ? null : "{$rating} / 5")
                        ->placeholder('Not provided'),
                    TextEntry::make('shared_with_team')
                        ->label('Shared with team')
                        ->state(fn (Survey $record): string => data_get($record->answers, 'share_with_team') ? 'Yes' : 'No'),
                ]),
        ]);
    }
}
