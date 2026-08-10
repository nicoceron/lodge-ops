<?php

namespace App\Filament\Resources\AutomationRules;

use App\Filament\Resources\AutomationRules\Pages\CreateAutomationRule;
use App\Filament\Resources\AutomationRules\Pages\EditAutomationRule;
use App\Filament\Resources\AutomationRules\Pages\ListAutomationRules;
use App\Filament\Resources\AutomationRules\Pages\ViewAutomationRule;
use App\Filament\Resources\AutomationRules\Schemas\AutomationRuleForm;
use App\Filament\Resources\AutomationRules\Schemas\AutomationRuleInfolist;
use App\Filament\Resources\AutomationRules\Tables\AutomationRulesTable;
use App\Filament\Resources\TenantResource;
use App\Models\AutomationRule;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AutomationRuleResource extends TenantResource
{
    protected static ?string $model = AutomationRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 20;

    protected static string $writeCapability = 'canManageConfiguration';

    protected static ?string $viewCapability = 'canManageConfiguration';

    public static function form(Schema $schema): Schema
    {
        return AutomationRuleForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AutomationRuleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AutomationRulesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAutomationRules::route('/'),
            'create' => CreateAutomationRule::route('/create'),
            'view' => ViewAutomationRule::route('/{record}'),
            'edit' => EditAutomationRule::route('/{record}/edit'),
        ];
    }
}
