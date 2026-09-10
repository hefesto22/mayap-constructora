<?php

declare(strict_types=1);

namespace App\Filament\Resources\Zonas;

use App\Filament\Resources\Zonas\Pages\CreateZona;
use App\Filament\Resources\Zonas\Pages\EditZona;
use App\Filament\Resources\Zonas\Pages\ListZonas;
use App\Filament\Resources\Zonas\Schemas\ZonaForm;
use App\Filament\Resources\Zonas\Tables\ZonasTable;
use App\Models\Zona;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Override;

class ZonaResource extends Resource
{
    protected static ?string $model = Zona::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Zona';

    protected static ?string $pluralModelLabel = 'Zonas';

    protected static ?int $navigationSort = 20;

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return 'Catálogos';
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Zonas';
    }

    #[Override]
    public static function getBreadcrumb(): string
    {
        return 'Zonas';
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return ZonaForm::configure($schema);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return ZonasTable::configure($table);
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index'  => ListZonas::route('/'),
            'create' => CreateZona::route('/create'),
            'edit'   => EditZona::route('/{record}/edit'),
        ];
    }

    #[Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'nombre'];
    }
}
