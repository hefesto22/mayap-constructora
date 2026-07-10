<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras;

use App\Filament\Resources\Compras\Pages\CreateCompra;
use App\Filament\Resources\Compras\Pages\EditCompra;
use App\Filament\Resources\Compras\Pages\ListCompras;
use App\Filament\Resources\Compras\Schemas\CompraForm;
use App\Filament\Resources\Compras\Tables\ComprasTable;
use App\Models\Compra;
use App\Models\User;
use App\Support\Roles;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CompraResource extends Resource
{
    protected static ?string $model = Compra::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $recordTitleAttribute = 'codigo';

    protected static ?string $modelLabel = 'Compra';

    protected static ?string $pluralModelLabel = 'Compras';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return 'Compras';
    }

    public static function form(Schema $schema): Schema
    {
        return CompraForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ComprasTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['proveedor:id,codigo,nombre', 'bodega:id,codigo,nombre']);

        // Fase 2: el usuario solo ve las compras de sus bodegas. Filtro inline
        // para conservar el tipo Builder<Model>; la regla canónica vive en
        // Compra::scopeVisibleParaUsuario.
        $user = auth()->user();

        if ($user instanceof User && ! $user->puedeVerTodasLasBodegas()) {
            if (Roles::soloEncargado($user)) {
                // G2: el encargado ve las compras que traen material a SUS
                // obras (cabecera directa o líneas mixtas) — su ventana
                // para verificar la recepción.
                $obras = $user->obrasEncargadas()->pluck('proyectos.id');

                $query->where(function (Builder $q) use ($obras): void {
                    $q->whereIn('proyecto_id', $obras)
                        ->orWhereHas('lineas', fn (Builder $l) => $l->whereIn('proyecto_id', $obras));
                });
            } else {
                $query->where(function (Builder $q) use ($user): void {
                    $q->whereIn('bodega_id', $user->bodegasAsignadasIds());

                    // Recepción también ve las compras DIRECTAS a obra
                    // (bodega null): ella las registra y les da seguimiento.
                    if (Roles::compra($user)) {
                        $q->orWhereNull('bodega_id');
                    }
                });
            }
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListCompras::route('/'),
            'create' => CreateCompra::route('/create'),
            'edit'   => EditCompra::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'numero_factura'];
    }
}
