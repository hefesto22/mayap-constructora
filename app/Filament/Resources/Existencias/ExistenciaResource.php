<?php

declare(strict_types=1);

namespace App\Filament\Resources\Existencias;

use App\Filament\Resources\Existencias\Pages\ListExistencias;
use App\Filament\Resources\Existencias\Tables\ExistenciasTable;
use App\Models\Existencia;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Vista de existencias — SOLO LECTURA. El stock es un saldo derivado del
 * libro mayor de movimientos; no se edita a mano. Las altas/bajas se hacen
 * con la acción "Registrar entrada" (y, más adelante, despachos de
 * requisiciones), que pasan por RegistrarMovimientoService.
 */
class ExistenciaResource extends Resource
{
    protected static ?string $model = Existencia::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $modelLabel = 'Existencia';

    protected static ?string $pluralModelLabel = 'Existencias';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return 'Inventario';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return ExistenciasTable::configure($table);
    }

    /**
     * Eager loading: la ubicación se arma con bodega o proyecto, y el
     * nombre/código del item — evita N+1 en el listado.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'item:id,codigo,nombre,unidad_medida_id',
                'item.unidadMedida:id,simbolo',
                'bodega:id,codigo,nombre',
                'proyecto:id,codigo,nombre',
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExistencias::route('/'),
        ];
    }
}
