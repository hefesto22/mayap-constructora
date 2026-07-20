<?php

declare(strict_types=1);

namespace App\Filament\Resources\Mantenimientos;

use App\Filament\Resources\Mantenimientos\Pages\ListMantenimientos;
use App\Filament\Resources\Mantenimientos\Pages\ViewMantenimiento;
use App\Filament\Resources\Mantenimientos\RelationManagers\BitacorasRelationManager;
use App\Filament\Resources\Mantenimientos\Schemas\MantenimientoInfolist;
use App\Filament\Resources\Mantenimientos\Tables\MantenimientosTable;
use App\Models\MantenimientoMaquina;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Mantenimientos de máquinas — SOLO LECTURA + acciones "Registrar avance"
 * y "Finalizar". Los mantenimientos se crean con la acción "Enviar a
 * mantenimiento" del catálogo de máquinas (MantenimientoService). Aquí se
 * consultan, se les registra el avance de la reparación (fases + bitácora
 * con fecha y hora) y se cierran.
 */
class MantenimientoMaquinaResource extends Resource
{
    protected static ?string $model = MantenimientoMaquina::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?string $recordTitleAttribute = 'codigo';

    protected static ?string $modelLabel = 'Mantenimiento';

    protected static ?string $pluralModelLabel = 'Mantenimientos';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return 'Maquinaria';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return MantenimientosTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MantenimientoInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [
            BitacorasRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['maquina:id,codigo,nombre', 'asignacionSustituta:id,codigo']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMantenimientos::route('/'),
            'view'  => ViewMantenimiento::route('/{record}'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'maquina.nombre'];
    }
}
