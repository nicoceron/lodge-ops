<?php

namespace App\Filament\Resources\ProviderEvents;

use App\Enums\ProviderEventState;
use App\Filament\Resources\ProviderEvents\Pages\ManageProviderEvents;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\ProviderEvent;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProviderEventResource extends TenantResource
{
    protected static ?string $model = ProviderEvent::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 13;

    protected static bool $canCreateRecords = false;

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    protected static ?string $viewCapability = 'canViewFinance';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('received_at')->label('Received')->dateTime('M j, Y · H:i:s', timezone: InnPresentation::timezone())->sortable(),
            TextColumn::make('action')->placeholder('—'),
            TextColumn::make('resource_id')->label('Resource')->copyable(),
            IconColumn::make('signature_valid')->label('Signature')->boolean(),
            TextColumn::make('processing_state')->label('Processing')->badge()->formatStateUsing(InnPresentation::label(...))->color(fn ($state): string => InnPresentation::statusColor($state)),
            TextColumn::make('attempt_count')->label('Attempts'),
            TextColumn::make('last_error')->label('Exception')->limit(70)->placeholder('—'),
        ])->filters([SelectFilter::make('processing_state')->options(InnPresentation::enumOptions(ProviderEventState::cases()))])
            ->defaultSort('received_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageProviderEvents::route('/')];
    }
}
