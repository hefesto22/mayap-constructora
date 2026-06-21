<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartesTrabajo;

use App\Filament\Resources\PartesTrabajo\Pages\ListPartesTrabajo;
use App\Filament\Resources\PartesTrabajo\Tables\PartesTrabajoTable;
use App\Models\ParteTrabajo;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Partes de trabajo — vista global SOLO LECTURA del cobro por horas de
 * maquinaria. Los partes se registran desde la asignación (acción "Registrar
 * parte" → RegistrarParteService). Aquí se consultan y se suman por obra/máquina.
 */
class ParteTrabajoResource extends Resource
{
    protected static ?string $model = ParteTrabajo::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $recordTitleAttribute = 'codigo';

    protected static ?string $modelLabel = 'Parte de trabajo';

    protected static ?string $pluralModelLabel = 'Partes de trabajo';

    protected static ?int $navigationSort = 30;

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
        return PartesTrabajoTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'asignacion:id,codigo,maquina_id,proyecto_id',
                'asignacion.maquina:id,nombre',
                'asignacion.proyecto:id,nombre',
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartesTrabajo::route('/'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo'];
    }
}
