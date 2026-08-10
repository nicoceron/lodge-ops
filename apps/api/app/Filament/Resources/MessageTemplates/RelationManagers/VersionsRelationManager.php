<?php

namespace App\Filament\Resources\MessageTemplates\RelationManagers;

use App\Filament\Support\LodgeOpsPresentation;
use App\Models\MessageTemplateVersion;
use App\Services\MessageTemplateService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('language')->options(['en' => 'English', 'es' => 'Spanish', 'pt' => 'Portuguese', 'fr' => 'French'])->searchable()->required()->default('en'),
            TextInput::make('subject')->maxLength(255),
            Textarea::make('body')->required()->rows(12)->columnSpanFull(),
        ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Message version')->columns(2)->schema([
                TextEntry::make('version')->prefix('v'),
                TextEntry::make('language')->badge(),
                TextEntry::make('published_at')->label('Published')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone())->placeholder('Draft'),
                TextEntry::make('subject')->placeholder('No subject')->columnSpanFull(),
                TextEntry::make('body')->markdown()->columnSpanFull(),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('version')->prefix('v')->sortable(),
                TextColumn::make('language')->badge(),
                TextColumn::make('subject')->placeholder('No subject')->limit(50),
                IconColumn::make('published_at')->label('Published')->boolean(fn (?string $state): bool => filled($state)),
                TextColumn::make('published_at')->label('Published at')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone())->placeholder('Draft')->sortable(),
            ])
            ->filters([TernaryFilter::make('published')->queries(true: fn ($query) => $query->whereNotNull('published_at'), false: fn ($query) => $query->whereNull('published_at'))])
            ->headerActions([
                CreateAction::make()->using(fn (array $data): MessageTemplateVersion => app(MessageTemplateService::class)->createVersion($this->getOwnerRecord(), $data['language'], $data['subject'] ?? null, $data['body'])),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (MessageTemplateVersion $record): bool => $record->published_at === null),
                Action::make('publish')->icon('heroicon-o-paper-airplane')->color('success')->requiresConfirmation()->visible(fn (MessageTemplateVersion $record): bool => $record->published_at === null)->action(function (MessageTemplateVersion $record): void {
                    app(MessageTemplateService::class)->publish($record);
                    Notification::make()->success()->title('Message version published and locked')->send();
                }),
            ])
            ->defaultSort('version', 'desc');
    }
}
