<?php

namespace App\Filament\Resources\Vouchers;

use App\Filament\Resources\TenantResource;
use App\Filament\Resources\Vouchers\Pages\CreateVoucher;
use App\Filament\Resources\Vouchers\Pages\ListVouchers;
use App\Filament\Support\InnPresentation;
use App\Models\CommercialPromotion;
use App\Models\Voucher;
use App\Services\VoucherCodeCanonicalizer;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VoucherResource extends TenantResource
{
    protected static ?string $model = Voucher::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static string|\UnitEnum|null $navigationGroup = 'Setup';

    protected static ?string $viewCapability = 'canManageReservations';

    protected static string $writeCapability = 'canManageConfiguration';

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make('Opaque voucher')->description('The canonical code is HMAC hashed; the raw code is never retained.')->columns(2)->schema([
            Select::make('property_id')->options(InnPresentation::propertyOptions(...))->required(),
            Select::make('commercial_promotion_id')->label('Published code promotion')->options(fn (): array => CommercialPromotion::query()->where('state', 'published')->where('requires_code', true)->pluck('name', 'id')->all())->required(),
            TextInput::make('code_hash')->label('Voucher code')->password()->revealable()->required()->minLength(VoucherCodeCanonicalizer::MIN_LENGTH)->maxLength(VoucherCodeCanonicalizer::MAX_LENGTH)
                ->dehydrateStateUsing(fn (string $state): string => app(VoucherCodeCanonicalizer::class)->hash(app(TenantContext::class)->id(), $state)),
            TextInput::make('public_label')->required()->maxLength(160),
            TextInput::make('usage_limit')->integer()->minValue(1), TextInput::make('per_guest_limit')->integer()->minValue(1),
            TextInput::make('per_session_limit')->integer()->minValue(1),
            TextInput::make('budget_minor')->integer()->minValue(1), DateTimePicker::make('valid_from'), DateTimePicker::make('valid_until')->after('valid_from'),
        ])]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('public_label')->searchable(), TextColumn::make('promotion.name'), TextColumn::make('property.name'),
            TextColumn::make('state')->badge(), TextColumn::make('redemptions_count')->counts('redemptions')->label('Uses'),
            TextColumn::make('valid_until')->dateTime()->placeholder('No expiry'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListVouchers::route('/'), 'create' => CreateVoucher::route('/create')];
    }
}
