<?php

namespace App\Filament\Resources\Resources\Schemas;

use App\Enums\MembershipRole;
use App\Enums\ResourceType;
use App\Filament\Support\LodgeOpsPresentation;
use App\Models\Membership;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ResourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Bookable resource')
                ->description('Rooms, people, transport and equipment share one availability engine.')
                ->columns(2)
                ->schema([
                    Select::make('property_id')
                        ->relationship('property', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('type')
                        ->options(LodgeOpsPresentation::enumOptions(ResourceType::cases()))
                        ->native(false)
                        ->required(),
                    Select::make('user_id')
                        ->label('Linked staff user')
                        ->helperText('Link guide resources to the staff account that owns this availability.')
                        ->options(fn (): array => Membership::query()
                            ->with('user')
                            ->where('role', MembershipRole::Guide)
                            ->where('is_active', true)
                            ->get()
                            ->filter(fn (Membership $membership): bool => $membership->user !== null)
                            ->mapWithKeys(fn (Membership $membership): array => [
                                $membership->user_id => "{$membership->user->name} · {$membership->user->email}",
                            ])->all())
                        ->searchable(),
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('code')
                        ->required()
                        ->maxLength(40)
                        ->alphaDash()
                        ->scopedUnique(ignoreRecord: true),
                    TextInput::make('capacity')
                        ->required()
                        ->integer()
                        ->minValue(1)
                        ->default(1),
                    Toggle::make('is_buyout')
                        ->label('Property-wide buyout')
                        ->helperText('An active allocation blocks every other resource at this property for the interval.')
                        ->default(false),
                    Toggle::make('is_active')
                        ->default(true)
                        ->required(),
                    TagsInput::make('attributes.specialties')
                        ->label('Specialties')
                        ->placeholder('Fly fishing, trekking, wildlife'),
                    TagsInput::make('attributes.capabilities')
                        ->label('Capabilities')
                        ->placeholder('First aid, trailer, 4x4'),
                    TagsInput::make('attributes.languages')
                        ->label('Languages')
                        ->placeholder('English, Spanish'),
                ]),
        ]);
    }
}
