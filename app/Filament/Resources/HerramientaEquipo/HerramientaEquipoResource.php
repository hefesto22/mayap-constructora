<?php

declare(strict_types=1);

namespace App\Filament\Resources\HerramientaEquipo;

use App\Enums\CategoriaItem;
use App\Filament\Resources\HerramientaEquipo\Pages\CreateHerramientaEquipo;
use App\Filament\Resources\HerramientaEquipo\Pages\EditHerramientaEquipo;
use App\Filament\Resources\HerramientaEquipo\Pages\ListHerramientaEquipo;
use App\Filament\Resources\Materiales\Schemas\MaterialForm;
use App\Filament\Resources\Materiales\Tables\MaterialsTable;
use App\Models\Material;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Herramienta y equipo — la MISMA tabla `materiales` (mismo inventario,
 * compras y requisiciones), pero como sección propia del grupo
 * Maquinaria (decisión Mauricio 2026-07-19: "en Materiales solo
 * materiales deberían ir").
 *
 * NO es un módulo paralelo: reusa modelo, form, tabla y policy de
 * Material. Solo cambia la puerta de entrada — el código sigue siendo
 * HE-#####, el stock sigue en bodegas y el presupuesto de materiales
 * las sigue excluyendo como siempre.
 */
class HerramientaEquipoResource extends Resource
{
    protected static ?string $model = Material::class;

    protected static ?string $slug = 'herramienta-y-equipo';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Herramienta y equipo';

    protected static ?string $pluralModelLabel = 'Herramienta y equipo';

    protected static ?int $navigationSort = 60;

    public static function getNavigationGroup(): ?string
    {
        return 'Maquinaria';
    }

    public static function form(Schema $schema): Schema
    {
        return MaterialForm::configure($schema, CategoriaItem::HerramientaEquipo);
    }

    public static function table(Table $table): Table
    {
        return MaterialsTable::configure($table, conCategoria: false);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('categoria', CategoriaItem::HerramientaEquipo->value)
            ->with(['unidadMedida:id,codigo,simbolo,nombre'])
            ->withCount('items');
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListHerramientaEquipo::route('/'),
            'create' => CreateHerramientaEquipo::route('/create'),
            'edit'   => EditHerramientaEquipo::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'nombre'];
    }
}
