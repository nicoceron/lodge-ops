<?php

namespace App\Filament\Resources\Resources\Schemas;

use App\Enums\MembershipRole;
use App\Enums\ResourceKind;
use App\Filament\Support\LodgeOpsPresentation;
use App\Models\Membership;
use App\Models\ResourceCategory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ResourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Bookable resource')
                ->description('Places, assets and crew share one availability engine. Categories are this property’s catalog.')
                ->columns(2)
                ->schema([
                    Select::make('property_id')
                        ->options(LodgeOpsPresentation::propertyOptions(...))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required(),
                    Select::make('category_id')
                        ->label('Category')
                        ->options(fn (Get $get): array => ResourceCategory::query()
                            ->when($get('property_id'), fn ($query, $propertyId) => $query->where('property_id', $propertyId))
                            ->where('is_active', true)
                            ->orderBy('sort_order')
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (ResourceCategory $category): array => [
                                $category->id => "{$category->name} · {$category->kind->singular()}",
                            ])->all())
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required(),
                    Select::make('user_id')
                        ->label('Linked staff user')
                        ->helperText('Link crew resources to the staff account that owns this availability.')
                        ->visible(fn (Get $get): bool => self::selectedKind($get) === ResourceKind::Crew)
                        ->options(fn (): array => Membership::query()
                            ->with('user')
                            ->where('role', MembershipRole::Guide)
                            ->where('is_active', true)
                            ->get()
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
                    TextInput::make('attributes.floor')
                        ->label('Floor / location')
                        ->visible(fn (Get $get): bool => self::selectedKind($get) === ResourceKind::Place)
                        ->maxLength(80),
                    Toggle::make('is_buyout')
                        ->label('Property-wide buyout')
                        ->helperText('An active allocation blocks every other resource at this property for the interval.')
                        ->default(false),
                    Toggle::make('is_active')
                        ->default(true)
                        ->required(),
                    TagsInput::make('attributes.specialties')
                        ->label('Specialties')
                        ->visible(fn (Get $get): bool => self::selectedKind($get) === ResourceKind::Crew)
                        ->placeholder('Fly fishing, trekking, wildlife'),
                    TagsInput::make('attributes.capabilities')
                        ->label('Capabilities')
                        ->placeholder('First aid, trailer, 4x4'),
                    TagsInput::make('attributes.languages')
                        ->label('Languages')
                        ->visible(fn (Get $get): bool => self::selectedKind($get) === ResourceKind::Crew)
                        ->placeholder('English, Spanish'),
                ]),
        ]);
    }

    private static function selectedKind(Get $get): ?ResourceKind
    {
        $categoryId = $get('category_id');
        if (! is_string($categoryId) || $categoryId === '') {
            return null;
        }

        return ResourceCategory::query()->find($categoryId)?->kind;
    }
}
