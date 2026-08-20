<?php

namespace App\Filament\Resources\ProviderDisputes;

use App\Filament\Resources\ProviderDisputes\Pages\ManageProviderDisputes;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\ProviderDispute;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProviderDisputeResource extends TenantResource
{
    protected static ?string $model = ProviderDispute::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 14;

    protected static bool $canCreateRecords = false;

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    protected static ?string $viewCapability = 'canViewFinance';

    protected static string $writeCapability = 'canManageMoney';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('updated_at')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->sortable(),
            TextColumn::make('reservation.confirmation_number')->label('Reservation'),
            TextColumn::make('state')->badge(),
            TextColumn::make('impact_state')->badge(),
            TextColumn::make('amount_minor')->money(fn (ProviderDispute $record): string => $record->currency, divideBy: 100),
            TextColumn::make('provider_dispute_id')->copyable(),
            TextColumn::make('current_revision')->label('Revision'),
        ])->recordActions([
            Action::make('recordInvestigation')->authorize('resolve')->schema([
                Textarea::make('notes')->required()->maxLength(2000),
            ])->action(function (ProviderDispute $record, array $data): void {
                $record->update(['resolved_by' => auth()->id(), 'resolved_at' => now(), 'resolution_notes' => $data['notes']]);
                Notification::make()->success()->title('Investigation note recorded; provider facts were not changed')->send();
            }),
        ])->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageProviderDisputes::route('/')];
    }
}
