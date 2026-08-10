<?php

namespace App\Filament\Resources\TeamMembers;

use App\Enums\MembershipRole;
use App\Filament\Resources\TeamMembers\Pages\CreateTeamMember;
use App\Filament\Resources\TeamMembers\Pages\EditTeamMember;
use App\Filament\Resources\TeamMembers\Pages\ListTeamMembers;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\LodgeOpsPresentation;
use App\Models\Membership;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TeamMemberResource extends TenantResource
{
    protected static ?string $model = Membership::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|\UnitEnum|null $navigationGroup = 'Setup';

    protected static ?string $navigationLabel = 'Team & access';

    protected static ?string $modelLabel = 'team member';

    protected static ?int $navigationSort = 30;

    protected static bool $canDeleteRecords = false;

    protected static string $writeCapability = 'canManageTeam';

    protected static ?string $viewCapability = 'canManageTeam';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Team member')
                ->description('Invite a teammate, scope their property, and choose the least-privileged role for their work.')
                ->columns(2)
                ->schema([
                    TextInput::make('member_name')
                        ->label('Name')
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated(fn (string $operation): bool => $operation === 'create')
                        ->maxLength(255),
                    TextInput::make('member_email')
                        ->label('Email')
                        ->email()
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated(fn (string $operation): bool => $operation === 'create')
                        ->maxLength(255),
                    Select::make('role')
                        ->options(LodgeOpsPresentation::enumOptions(MembershipRole::cases()))
                        ->native(false)
                        ->required(),
                    Select::make('property_id')
                        ->label('Property scope')
                        ->relationship('property', 'name')
                        ->placeholder('All properties')
                        ->searchable()
                        ->preload(),
                    Toggle::make('is_active')
                        ->label('Active access')
                        ->default(true)
                        ->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('Team member')->description(fn (Membership $record): string => $record->user->email)->searchable(),
                TextColumn::make('role')->badge()->formatStateUsing(fn (MembershipRole $state): string => str($state->value)->headline()->toString()),
                TextColumn::make('property.name')->label('Property scope')->placeholder('All properties'),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading('No team members yet')
            ->emptyStateDescription('Invite operations, guides, kitchen, housekeeping, finance, and owners with focused access.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeamMembers::route('/'),
            'create' => CreateTeamMember::route('/create'),
            'edit' => EditTeamMember::route('/{record}/edit'),
        ];
    }
}
