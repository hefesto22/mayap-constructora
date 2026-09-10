<?php

declare(strict_types=1);

namespace App\Filament\Resources\Maquinas;

use App\Filament\Resources\Maquinas\Pages\CreateMaquina;
use App\Filament\Resources\Maquinas\Pages\EditMaquina;
use App\Filament\Resources\Maquinas\Pages\HojaDeVidaMaquina;
use App\Filament\Resources\Maquinas\Pages\ListMaquinas;
use App\Filament\Resources\Maquinas\RelationManagers\PlanesMantenimientoRelationManager;
use App\Filament\Resources\Maquinas\Schemas\MaquinaForm;
use App\Filament\Resources\Maquinas\Tables\MaquinasTable;
use App\Models\Maquina;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Override;

class MaquinaResource extends Resource
{
    protected static ?string $model = Maquina::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Máquina';

    protected static ?string $pluralModelLabel = 'Maquinaria';

    protected static ?int $navigationSort = 10;

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return 'Maquinaria';
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return MaquinaForm::configure($schema);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return MaquinasTable::configure($table);
    }

    #[Override]
    public static function getRelations(): array
    {
        return [
            PlanesMantenimientoRelationManager::class,
        ];
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index'        => ListMaquinas::route('/'),
            'create'       => CreateMaquina::route('/create'),
            'edit'         => EditMaquina::route('/{record}/edit'),
            'hoja-de-vida' => HojaDeVidaMaquina::route('/{record}/hoja-de-vida'),
        ];
    }

    #[Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'nombre', 'serie', 'marca'];
    }
}
