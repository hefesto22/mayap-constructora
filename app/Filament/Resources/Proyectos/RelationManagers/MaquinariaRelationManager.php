<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\RelationManagers;

use App\Models\AgendaMaquina;
use App\Models\ParteTrabajo;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Historial de MAQUINARIA del proyecto — responde "¿la máquina FUE?":
 * cada agendado (qué día y a qué hora llegaba) con su cumplimiento real,
 * cruzado contra los partes de trabajo (verde): "✓ trabajó Nh" o "sin
 * reporte". Solo lectura: se agenda desde el calendario o por solicitud.
 */
class MaquinariaRelationManager extends RelationManager
{
    protected static string $relationship = 'agendaMaquina';

    protected static ?string $title = 'Maquinaria';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/M/Y')
                    ->sortable()
                    ->badge()
                    ->color(fn (AgendaMaquina $record): string => $record->fecha->isToday()
                        ? 'warning'
                        : ($record->fecha->isPast() ? 'gray' : 'info')),

                TextColumn::make('maquina.nombre')
                    ->label('Máquina')
                    ->searchable(),

                TextColumn::make('hora_entrada')
                    ->label('Llegada')
                    ->formatStateUsing(fn (AgendaMaquina $record): string => $record->horaEntrada12() ?? '—')
                    ->placeholder('—'),

                // ¿La máquina FUE? La prueba es el parte real de ese día:
                // horas trabajadas = fue; sin parte = sin reporte (si el
                // día ya pasó) o pendiente (si aún no llega).
                TextColumn::make('cumplimiento')
                    ->label('¿Trabajó?')
                    ->badge()
                    ->state(function (AgendaMaquina $record): string {
                        $horas = ParteTrabajo::query()
                            ->whereHas('asignacion', fn ($q) => $q
                                ->where('maquina_id', $record->maquina_id)
                                ->where('proyecto_id', $record->proyecto_id))
                            ->whereDate('fecha', $record->fecha)
                            ->sum('horas');

                        if ((float) $horas > 0) {
                            return '✓ Trabajó '.rtrim(rtrim(number_format((float) $horas, 2, '.', ''), '0'), '.').'h';
                        }

                        return $record->fecha->isPast() && ! $record->fecha->isToday()
                            ? 'Sin reporte'
                            : 'Programada';
                    })
                    ->color(fn (string $state): string => match (true) {
                        str_starts_with($state, '✓') => 'success',
                        $state === 'Sin reporte'     => 'danger',
                        default                      => 'info',
                    }),

                TextColumn::make('notas')
                    ->label('Notas')
                    ->limit(30)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('fecha', 'desc')
            ->emptyStateHeading('Sin maquinaria en este proyecto')
            ->emptyStateDescription('Cuando se agende una máquina a esta obra, aquí queda el historial de cada día y si trabajó.')
            ->paginated([25, 50]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
