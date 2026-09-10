<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proveedores;

use App\Filament\Resources\Proveedores\Pages\CreateProveedor;
use App\Filament\Resources\Proveedores\Pages\EditProveedor;
use App\Filament\Resources\Proveedores\Pages\ListProveedores;
use App\Filament\Resources\Proveedores\Schemas\ProveedorForm;
use App\Filament\Resources\Proveedores\Tables\ProveedoresTable;
use App\Models\Proveedor;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Override;

class ProveedorResource extends Resource
{
    protected static ?string $model = Proveedor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Proveedor';

    protected static ?string $pluralModelLabel = 'Proveedores';

    protected static ?int $navigationSort = 10;

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return 'Compras';
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return ProveedorForm::configure($schema);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return ProveedoresTable::configure($table);
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index'  => ListProveedores::route('/'),
            'create' => CreateProveedor::route('/create'),
            'edit'   => EditProveedor::route('/{record}/edit'),
        ];
    }

    #[Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'nombre', 'rtn'];
    }
}
