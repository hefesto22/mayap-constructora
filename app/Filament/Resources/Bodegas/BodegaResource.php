<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bodegas;

use App\Filament\Resources\Bodegas\Pages\CreateBodega;
use App\Filament\Resources\Bodegas\Pages\EditBodega;
use App\Filament\Resources\Bodegas\Pages\ListBodegas;
use App\Filament\Resources\Bodegas\Schemas\BodegaForm;
use App\Filament\Resources\Bodegas\Tables\BodegasTable;
use App\Models\Bodega;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class BodegaResource extends Resource
{
    protected static ?string $model = Bodega::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Bodega';

    protected static ?string $pluralModelLabel = 'Bodegas';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return 'Inventario';
    }

    public static function form(Schema $schema): Schema
    {
        return BodegaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BodegasTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListBodegas::route('/'),
            'create' => CreateBodega::route('/create'),
            'edit'   => EditBodega::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'nombre', 'responsable'];
    }
}
