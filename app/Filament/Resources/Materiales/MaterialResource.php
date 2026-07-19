<?php

declare(strict_types=1);

namespace App\Filament\Resources\Materiales;

use App\Enums\CategoriaItem;
use App\Filament\Resources\Materiales\Pages\CreateMaterial;
use App\Filament\Resources\Materiales\Pages\EditMaterial;
use App\Filament\Resources\Materiales\Pages\ListMateriales;
use App\Filament\Resources\Materiales\Schemas\MaterialForm;
use App\Filament\Resources\Materiales\Tables\MaterialsTable;
use App\Models\Material;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Catálogo de MATERIALES físicos (ADR-0003) — el recurso único y global
 * que se compra, almacena y consume. Es la fuente del inventario: cada
 * existencia, compra y requisición referencia un material de aquí.
 *
 * Se distingue de "Base de precios" (ItemResource), que es el precio de
 * venta POR ZONA. Varios items (uno por zona) pueden apuntar al mismo
 * material vía items.material_id.
 */
class MaterialResource extends Resource
{
    protected static ?string $model = Material::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Material';

    protected static ?string $pluralModelLabel = 'Materiales';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return 'Inventario';
    }

    public static function form(Schema $schema): Schema
    {
        return MaterialForm::configure($schema, CategoriaItem::Materiales);
    }

    public static function table(Table $table): Table
    {
        return MaterialsTable::configure($table, conCategoria: false);
    }

    /**
     * Eager loading + conteo de items (precios por zona) ligados — evita N+1
     * en el listado y permite mostrar en cuántas zonas tiene precio.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            // SOLO materiales: herramienta y equipo tiene su propia seccion
            // en el grupo Maquinaria (mismo catalogo, otra puerta).
            ->where('categoria', CategoriaItem::Materiales->value)
            ->with(['unidadMedida:id,codigo,simbolo,nombre'])
            ->withCount('items');
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListMateriales::route('/'),
            'create' => CreateMaterial::route('/create'),
            'edit'   => EditMaterial::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'nombre'];
    }
}
