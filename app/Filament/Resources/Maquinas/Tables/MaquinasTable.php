<?php

declare(strict_types=1);

namespace App\Filament\Resources\Maquinas\Tables;

use App\Enums\AlertaMantenimiento;
use App\Enums\EstadoMaquina;
use App\Enums\TipoMaquina;
use App\Filament\Resources\Mantenimientos\MantenimientoMaquinaResource;
use App\Filament\Resources\Maquinas\Actions\AccionEnviarAMantenimiento;
use App\Filament\Resources\Maquinas\Actions\AccionLiberarDeObra;
use App\Filament\Resources\Maquinas\Actions\AccionMarcarReparada;
use App\Filament\Resources\Maquinas\MaquinaResource;
use App\Models\Maquina;
use App\Models\PlanMantenimiento;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class MaquinasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Para pintar "Trabajando · OBRA" y la alerta de mantenimiento
            // sin N+1: la estadía abierta y los planes viajan con cada fila.
            ->modifyQueryUsing(fn ($query) => $query->with(['agendaHoyConfirmada.proyecto:id,nombre', 'planesMantenimiento', 'mantenimientoEnProceso', 'asignacionActiva.proyecto:id,nombre']))
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-o-truck')
                    ->limit(35),
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (TipoMaquina $state): string => $state->getLabel())
                    ->sortable(),
                TextColumn::make('horometro_actual')
                    ->label('Horómetro')
                    ->numeric(2)
                    ->suffix(' h')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(),
                // Alerta de mantenimiento preventivo (decisión Mauricio
                // 2026-07-19): el PEOR plan activo manda — VENCIDO gana a
                // PRÓXIMO gana a Al día. Derivada al vuelo, nunca guardada.
                TextColumn::make('mantenimiento')
                    ->label('Mantenimiento')
                    ->badge()
                    ->state(function (Maquina $record): string {
                        $plan = $record->planPeorAlerta();

                        if (! $plan instanceof PlanMantenimiento) {
                            return 'Sin plan';
                        }

                        $estado = $plan->estadoAlerta();

                        return $estado === AlertaMantenimiento::AlDia
                            ? $estado->getLabel()
                            : $estado->getLabel().' · '.Str::limit($plan->nombre, 22);
                    })
                    ->color(fn (Maquina $record): string => $record->planPeorAlerta()?->estadoAlerta()->getColor() ?? 'gray')
                    ->icon(fn (Maquina $record): ?string => $record->planPeorAlerta()?->estadoAlerta()->getIcon())
                    ->tooltip(fn (Maquina $record): ?string => $record->planPeorAlerta()?->usoResumen())
                    ->toggleable(),
                TextColumn::make('tarifa_hora')
                    ->label('Tarifa/h')
                    ->money('HNL')
                    ->alignEnd()
                    ->sortable(),
                // Estado del ciclo de vida, con una capa VISUAL encima
                // (decisión Mauricio 2026-07-15): llegada confirmada HOY →
                // "Trabajando · OBRA" todo el día; mañana vuelve sola.
                // Taller y baja siempre ganan (trabajandoHoy los excluye).
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (EstadoMaquina $state, Maquina $record): string => $record->trabajandoHoy() ? 'info' : $state->getColor())
                    ->icon(fn (EstadoMaquina $state, Maquina $record): string => $record->trabajandoHoy() ? 'heroicon-o-play-circle' : $state->getIcon())
                    ->formatStateUsing(fn (EstadoMaquina $state, Maquina $record): string => $record->trabajandoHoy()
                        ? 'Trabajando · '.Str::limit((string) $record->obraDondeTrabajaHoy(), 22)
                        : $state->getLabel())
                    ->tooltip(fn (Maquina $record): ?string => self::pistaDeEstado($record))
                    // En mantenimiento el badge lleva AL expediente: la
                    // máquina deja de ser un callejón sin salida.
                    ->url(fn (Maquina $record): ?string => self::enlaceDelTaller($record))
                    ->sortable(),
                ToggleColumn::make('activo')
                    ->label('Activa')
                    ->onColor('success')
                    ->offColor('danger'),
            ])
            ->defaultSort('codigo', 'asc')
            ->filters([
                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(TipoMaquina::options()),
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(EstadoMaquina::options()),
                TernaryFilter::make('activo')
                    ->label('Estado de registro')
                    ->placeholder('Todas')
                    ->trueLabel('Activas')
                    ->falseLabel('Inactivas'),
            ])
            // Menú ⋮ fijo al final: con la tabla ancha la última acción
            // quedaba fuera de pantalla y la máquina en el taller
            // parecía no tener salida (corregido 2026-08-16).
            ->recordActions([
                ActionGroup::make([
                    Action::make('hoja_de_vida')
                        ->label('Hoja de vida')
                        ->icon('heroicon-o-identification')
                        ->color('gray')
                        ->url(fn (Maquina $record): string => MaquinaResource::getUrl(
                            'hoja-de-vida',
                            ['record' => $record],
                        )),
                    EditAction::make(),
                    AccionEnviarAMantenimiento::make(),
                    AccionMarcarReparada::make(),
                    AccionLiberarDeObra::make(),
                    DeleteAction::make(),
                ])
                    ->label('Acciones')
                    ->tooltip('Acciones'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Qué cuenta el badge de estado al pasar el mouse: la obra donde
     * trabaja hoy, o en qué va la reparación que la tiene parada.
     */
    private static function pistaDeEstado(Maquina $record): ?string
    {
        if ($record->trabajandoHoy()) {
            $llegada = $record->agendaHoyConfirmada?->llegada_confirmada_at;

            // La estadía puede llevar días abierta: "llegó hoy a las 7"
            // sería mentira el jueves de la máquina que llegó el lunes.
            return $llegada?->isToday() ?? false
                ? 'Llegada confirmada hoy a las '.$llegada->format('g:i A').' en '.$record->obraDondeTrabajaHoy()
                : 'En '.$record->obraDondeTrabajaHoy().' desde el '.$llegada?->format('d/m/Y');
        }

        if ($record->estado === EstadoMaquina::Asignada) {
            $asignacion = $record->asignacionActiva;

            return $asignacion === null
                ? 'Asignada sin asignación abierta — usa "Liberar de la obra" para devolverla al parque.'
                : "{$asignacion->codigo} · en {$asignacion->proyecto->nombre} desde el {$asignacion->fecha_inicio->format('d/m/Y')}";
        }

        if ($record->estado !== EstadoMaquina::Mantenimiento) {
            return null;
        }

        $taller = $record->mantenimientoEnProceso;

        if ($taller === null) {
            return 'En el taller sin reparación abierta — usa "Marcar como reparada" para devolverla al parque.';
        }

        return "{$taller->codigo} · en el taller desde el {$taller->fecha_inicio->format('d/m/Y')}"
            ." · fase: {$taller->fase->getLabel()} · prioridad: {$taller->prioridad->getLabel()}";
    }

    /**
     * El expediente de la reparación que tiene parada a la máquina (solo
     * para quien puede verlo; misma regla que el calendario).
     */
    private static function enlaceDelTaller(Maquina $record): ?string
    {
        if ($record->estado !== EstadoMaquina::Mantenimiento || $record->mantenimientoEnProceso === null) {
            return null;
        }

        if (! (auth()->user()?->can('View:MantenimientoMaquina') ?? false)) {
            return null;
        }

        return MantenimientoMaquinaResource::getUrl('view', ['record' => $record->mantenimientoEnProceso]);
    }
}
