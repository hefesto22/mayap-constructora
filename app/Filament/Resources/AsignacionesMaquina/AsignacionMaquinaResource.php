<?php

declare(strict_types=1);

namespace App\Filament\Resources\AsignacionesMaquina;

use App\Filament\Resources\AsignacionesMaquina\Pages\ListAsignacionesMaquina;
use App\Filament\Resources\AsignacionesMaquina\Pages\ViewAsignacionMaquina;
use App\Filament\Resources\AsignacionesMaquina\RelationManagers\ConsumosRelationManager;
use App\Filament\Resources\AsignacionesMaquina\RelationManagers\PartesRelationManager;
use App\Filament\Resources\AsignacionesMaquina\Schemas\AsignacionMaquinaInfolist;
use App\Filament\Resources\AsignacionesMaquina\Tables\AsignacionesMaquinaTable;
use App\Models\AsignacionMaquina;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Asignaciones de máquina a obra. No se crean con el formulario estándar:
 * se usan las acciones "Asignar" y "Finalizar" que pasan por
 * AsignarMaquinaService (mantiene en sincronía el estado de la máquina).
 */
class AsignacionMaquinaResource extends Resource
{
    protected static ?string $model = AsignacionMaquina::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $recordTitleAttribute = 'codigo';

    protected static ?string $modelLabel = 'Asignación';

    protected static ?string $pluralModelLabel = 'Asignaciones';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return 'Maquinaria';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return AsignacionMaquinaInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AsignacionesMaquinaTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PartesRelationManager::class,
            ConsumosRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['maquina:id,codigo,nombre', 'proyecto:id,codigo,nombre']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAsignacionesMaquina::route('/'),
            'view'  => ViewAsignacionMaquina::route('/{record}'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'maquina.nombre', 'proyecto.nombre'];
    }
}
