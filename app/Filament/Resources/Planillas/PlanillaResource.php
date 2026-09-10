<?php

declare(strict_types=1);

namespace App\Filament\Resources\Planillas;

use App\Filament\Resources\Planillas\Pages\CreatePlanilla;
use App\Filament\Resources\Planillas\Pages\EditPlanilla;
use App\Filament\Resources\Planillas\Pages\ListPlanillas;
use App\Filament\Resources\Planillas\Schemas\PlanillaForm;
use App\Filament\Resources\Planillas\Tables\PlanillasTable;
use App\Models\Planilla;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Override;

class PlanillaResource extends Resource
{
    protected static ?string $model = Planilla::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $recordTitleAttribute = 'codigo';

    protected static ?string $modelLabel = 'Planilla';

    protected static ?string $pluralModelLabel = 'Planillas';

    protected static ?int $navigationSort = 20;

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return 'Planilla';
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return PlanillaForm::configure($schema);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return PlanillasTable::configure($table);
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index'  => ListPlanillas::route('/'),
            'create' => CreatePlanilla::route('/create'),
            'edit'   => EditPlanilla::route('/{record}/edit'),
        ];
    }

    #[Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo'];
    }
}
