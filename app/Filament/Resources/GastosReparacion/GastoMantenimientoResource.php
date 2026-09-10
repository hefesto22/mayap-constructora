<?php

declare(strict_types=1);

namespace App\Filament\Resources\GastosReparacion;

use App\Filament\Resources\GastosReparacion\Pages\CreateGastoMantenimiento;
use App\Filament\Resources\GastosReparacion\Pages\EditGastoMantenimiento;
use App\Filament\Resources\GastosReparacion\Pages\ListGastosMantenimiento;
use App\Filament\Resources\GastosReparacion\Schemas\GastoMantenimientoForm;
use App\Filament\Resources\GastosReparacion\Tables\GastosMantenimientoTable;
use App\Models\GastoMantenimiento;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Override;

class GastoMantenimientoResource extends Resource
{
    protected static ?string $model = GastoMantenimiento::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'descripcion';

    protected static ?string $modelLabel = 'Gasto de reparación';

    protected static ?string $pluralModelLabel = 'Gastos de reparación';

    protected static ?int $navigationSort = 45;

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return 'Maquinaria';
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Gastos de reparación';
    }

    #[Override]
    public static function getBreadcrumb(): string
    {
        return 'Gastos de reparación';
    }

    /**
     * Lo que la obra pagó y nadie respaldó todavía: es la bandeja de
     * recepción, así que se cuenta en el menú.
     */
    public static function getNavigationBadge(): ?string
    {
        $pendientes = GastoMantenimiento::query()->pendientes()->count();

        return $pendientes > 0 ? (string) $pendientes : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return GastoMantenimientoForm::configure($schema);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return GastosMantenimientoTable::configure($table);
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index'  => ListGastosMantenimiento::route('/'),
            'create' => CreateGastoMantenimiento::route('/create'),
            'edit'   => EditGastoMantenimiento::route('/{record}/edit'),
        ];
    }

    #[Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'descripcion'];
    }
}
