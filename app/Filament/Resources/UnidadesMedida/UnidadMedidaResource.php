<?php

declare(strict_types=1);

namespace App\Filament\Resources\UnidadesMedida;

use App\Filament\Resources\UnidadesMedida\Pages\CreateUnidadMedida;
use App\Filament\Resources\UnidadesMedida\Pages\EditUnidadMedida;
use App\Filament\Resources\UnidadesMedida\Pages\ListUnidadesMedida;
use App\Filament\Resources\UnidadesMedida\Schemas\UnidadMedidaForm;
use App\Filament\Resources\UnidadesMedida\Tables\UnidadesMedidaTable;
use App\Models\UnidadMedida;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Override;

class UnidadMedidaResource extends Resource
{
    protected static ?string $model = UnidadMedida::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Unidad de medida';

    protected static ?string $pluralModelLabel = 'Unidades de medida';

    protected static ?int $navigationSort = 10;

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return 'Catálogos';
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Unidades de medida';
    }

    #[Override]
    public static function getBreadcrumb(): string
    {
        return 'Unidades de medida';
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return UnidadMedidaForm::configure($schema);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return UnidadesMedidaTable::configure($table);
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index'  => ListUnidadesMedida::route('/'),
            'create' => CreateUnidadMedida::route('/create'),
            'edit'   => EditUnidadMedida::route('/{record}/edit'),
        ];
    }

    #[Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'nombre'];
    }
}
