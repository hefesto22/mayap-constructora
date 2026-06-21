<?php

declare(strict_types=1);

namespace App\Filament\Resources\CuentasPorCobrar;

use App\Filament\Resources\CuentasPorCobrar\Pages\CreateCuentaPorCobrar;
use App\Filament\Resources\CuentasPorCobrar\Pages\EditCuentaPorCobrar;
use App\Filament\Resources\CuentasPorCobrar\Pages\ListCuentasPorCobrar;
use App\Filament\Resources\CuentasPorCobrar\Pages\ViewCuentaPorCobrar;
use App\Filament\Resources\CuentasPorCobrar\RelationManagers\CobrosRelationManager;
use App\Filament\Resources\CuentasPorCobrar\Schemas\CuentaPorCobrarForm;
use App\Filament\Resources\CuentasPorCobrar\Schemas\CuentaPorCobrarInfolist;
use App\Filament\Resources\CuentasPorCobrar\Tables\CuentasPorCobrarTable;
use App\Models\CuentaPorCobrar;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cuentas por cobrar — lo que los clientes le deben a MAYAP. Se registran a
 * mano (no hay facturación automática todavía) y se bajan con cobros
 * (CobrarService). Espejo de Cuentas por Pagar.
 */
class CuentaPorCobrarResource extends Resource
{
    protected static ?string $model = CuentaPorCobrar::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'codigo';

    protected static ?string $modelLabel = 'Cuenta por cobrar';

    protected static ?string $pluralModelLabel = 'Cuentas por cobrar';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return 'Comercial';
    }

    public static function form(Schema $schema): Schema
    {
        return CuentaPorCobrarForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CuentaPorCobrarInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CuentasPorCobrarTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CobrosRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['cliente:id,codigo,nombre', 'proyecto:id,codigo,nombre']);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListCuentasPorCobrar::route('/'),
            'create' => CreateCuentaPorCobrar::route('/create'),
            'view'   => ViewCuentaPorCobrar::route('/{record}'),
            'edit'   => EditCuentaPorCobrar::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'cliente.nombre', 'concepto'];
    }
}
