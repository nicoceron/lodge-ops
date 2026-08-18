<?php

namespace App\Filament\Resources\CommunicationSuppressions;

use App\Filament\Resources\CommunicationSuppressions\Pages\ManageCommunicationSuppressions;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\CommunicationSuppression;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CommunicationSuppressionResource extends TenantResource
{
    protected static ?string $model = CommunicationSuppression::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-no-symbol';

    protected static string|\UnitEnum|null $navigationGroup = 'Templates & Integrations';

    protected static ?int $navigationSort = 40;

    protected static ?string $viewCapability = 'canManageConfiguration';

    protected static string $writeCapability = 'canManageConfiguration';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Communication suppression')->description('The recipient is normalized and stored only as a SHA-256 hash.')->columns(2)->schema([
                Select::make('channel')->options(['email' => 'Email', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'])->required(),
                TextInput::make('recipient')->label('Recipient address or number')->required()->maxLength(255)->visible(fn (string $operation): bool => $operation === 'create'),
                TextInput::make('recipient_hash')->label('Recipient SHA-256')->disabled()->dehydrated(false)->visible(fn (string $operation): bool => $operation !== 'create')->columnSpanFull(),
                Select::make('reason')->options(['unsubscribe' => 'Unsubscribed', 'complaint' => 'Complaint', 'bounce' => 'Hard bounce', 'manual' => 'Manual block'])->required(),
                DateTimePicker::make('expires_at')->label('Expires')->seconds(false)->after('now'),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Suppression')->columns(2)->schema([
                TextEntry::make('channel')->badge()->formatStateUsing(InnPresentation::label(...)),
                TextEntry::make('reason')->badge()->formatStateUsing(InnPresentation::label(...)),
                TextEntry::make('recipient_hash')->label('Recipient SHA-256')->copyable()->columnSpanFull(),
                TextEntry::make('expires_at')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->placeholder('Never expires'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('channel')->badge()->formatStateUsing(InnPresentation::label(...)),
                TextColumn::make('recipient_hash')->label('Recipient hash')->limit(16)->copyable()->searchable(),
                TextColumn::make('reason')->badge()->formatStateUsing(InnPresentation::label(...)),
                TextColumn::make('expires_at')->label('Expires')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->placeholder('Never')->sortable(),
                TextColumn::make('created_at')->label('Added')->since()->sortable(),
            ])
            ->filters([SelectFilter::make('channel')->options(['email' => 'Email', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'])])
            ->recordActions([ViewAction::make(), EditAction::make(), DeleteAction::make()])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No suppressed recipients');
    }

    public static function getPages(): array
    {
        return ['index' => ManageCommunicationSuppressions::route('/')];
    }
}
