<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items;

use App\Filament\Resources\Items\Pages\CreateItem;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\Pages\ListItems;
use App\Filament\Resources\Items\Schemas\ItemForm;
use App\Filament\Resources\Items\Tables\ItemsTable;
use App\Models\Item;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ItemResource extends Resource
{
    protected static ?string $model = Item::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Item';

    protected static ?string $pluralModelLabel = 'Base de precios';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return 'Catálogos';
    }

    public static function getNavigationLabel(): string
    {
        return 'Base de precios';
    }

    public static function getBreadcrumb(): string
    {
        return 'Base de precios';
    }

    public static function form(Schema $schema): Schema
    {
        return ItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ItemsTable::configure($table);
    }

    /**
     * Eager loading global del Resource — evita N+1 en el listado.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['zona:id,codigo,nombre', 'unidadMedida:id,codigo,simbolo,nombre']);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListItems::route('/'),
            'create' => CreateItem::route('/create'),
            'edit'   => EditItem::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'nombre'];
    }
}
