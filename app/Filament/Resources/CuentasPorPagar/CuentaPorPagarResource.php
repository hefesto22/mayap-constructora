<?php

declare(strict_types=1);

namespace App\Filament\Resources\CuentasPorPagar;

use App\Filament\Resources\CuentasPorPagar\Pages\ListCuentasPorPagar;
use App\Filament\Resources\CuentasPorPagar\Pages\ViewCuentaPorPagar;
use App\Filament\Resources\CuentasPorPagar\RelationManagers\AbonosRelationManager;
use App\Filament\Resources\CuentasPorPagar\Schemas\CuentaPorPagarInfolist;
use App\Filament\Resources\CuentasPorPagar\Tables\CuentasPorPagarTable;
use App\Models\CuentaPorPagar;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Override;

/**
 * Cuentas por pagar — SOLO LECTURA + acción Abonar. La cuenta nace al
 * confirmar una compra a crédito (ConfirmarCompraService); no se crea ni se
 * edita a mano. El saldo solo se mueve con abonos (AbonarService).
 */
class CuentaPorPagarResource extends Resource
{
    protected static ?string $model = CuentaPorPagar::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'compra.codigo';

    protected static ?string $modelLabel = 'Cuenta por pagar';

    protected static ?string $pluralModelLabel = 'Cuentas por pagar';

    protected static ?int $navigationSort = 30;

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return 'Compras';
    }

    #[Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return CuentaPorPagarInfolist::configure($schema);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return CuentasPorPagarTable::configure($table);
    }

    #[Override]
    public static function getRelations(): array
    {
        return [
            AbonosRelationManager::class,
        ];
    }

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['compra:id,codigo', 'proveedor:id,nombre']);
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListCuentasPorPagar::route('/'),
            'view'  => ViewCuentaPorPagar::route('/{record}'),
        ];
    }

    #[Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['compra.codigo', 'proveedor.nombre'];
    }
}
