<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Tables;

use App\Enums\EstadoProyecto;
use App\Enums\TipoProyecto;
use App\Filament\Resources\Proyectos\Actions\AccionesEjecucion;
use App\Filament\Support\CostoObra;
use App\Models\Proyecto;
use App\Models\Zona;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProyectosTable
{
    /**
     * ¿La tab activa del listado corresponde a la fase de ejecución?
     *
     * Las columnas son contextuales a la fase: en tabs comerciales
     * (borrador, enviada, aprobada...) se muestran fechas de emisión y
     * validez; en tabs de ejecución (en ejecución, pausada, finalizada)
     * se muestran costo real, margen, avance y plazo.
     */
    private static function faseEjecucionActiva(mixed $livewire): bool
    {
        $tab = $livewire->activeTab ?? null;

        return is_string($tab)
            && (EstadoProyecto::tryFrom($tab)?->esEjecucion() ?? false);
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // Identidad en UNA columna: código arriba (mono, copiable) y
                // la zona debajo. Antes eran dos columnas para dos datos que
                // siempre se leen juntos.
                TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->description(fn (Proyecto $record): string => $record->zona->codigo),

                // Sin icono y con label corto: el color del badge ya distingue
                // presupuesto (azul) de renta (ámbar) sin gritar.
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (TipoProyecto $state): string => $state->getColor())
                    ->formatStateUsing(fn (TipoProyecto $state): string => $state === TipoProyecto::RentaMaquinaria
                        ? 'Renta'
                        : 'Presupuesto')
                    ->sortable(),

                // Obra + cliente juntos: es la pregunta "¿qué obra y de quién?".
                // Sin wrap, para que la fila no crezca a cuatro líneas.
                TextColumn::make('nombre')
                    ->label('Proyecto')
                    // El array de searchable() NO resuelve relaciones fuera de
                    // la propia columna (Filament interpretó 'cliente.nombre'
                    // como campo JSON y generó lower("cliente"->>'nombre')).
                    // Con query: se arma a mano, agrupado para no romper el AND
                    // con los filtros de la tabla.
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $termino = '%'.$search.'%';

                        return $query->where(fn (Builder $q): Builder => $q
                            ->where('nombre', 'ilike', $termino)
                            ->orWhereHas('cliente', fn (Builder $c): Builder => $c->where('nombre', 'ilike', $termino)));
                    })
                    ->limit(42)
                    ->tooltip(fn (Proyecto $record): string => $record->nombre)
                    ->description(fn (Proyecto $record): string => Str::limit($record->cliente->nombre, 42)),

                // La tab activa YA dice el estado: mostrarlo en cada fila era
                // repetir la misma palabra cinco veces. Queda disponible en el
                // menú de columnas para quien filtre por otra vía.
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (EstadoProyecto $state): string => $state->getColor())
                    ->icon(fn (EstadoProyecto $state): string => $state->getIcon())
                    ->formatStateUsing(fn (EstadoProyecto $state): string => $state->getLabel())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                // Emitido + vence en una sola celda. Se pinta en rojo solo en
                // Enviada: es el único estado donde el vencimiento es
                // accionable (follow-up con el cliente).
                TextColumn::make('fecha_emision')
                    ->label('Vigencia')
                    ->date('d/M/Y')
                    ->sortable()
                    ->description(fn (Proyecto $record): string => 'vence '.$record->fecha_validez->translatedFormat('d/M/Y'))
                    ->color(fn (Proyecto $record): string => $record->estado === EstadoProyecto::Enviada && $record->fecha_validez->isPast()
                        ? 'danger'
                        : 'gray')
                    ->visible(fn (mixed $livewire): bool => ! self::faseEjecucionActiva($livewire)),

                TextColumn::make('renglones_count')
                    ->label('Renglones')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray'),

                TextColumn::make('total_cache')
                    ->label('Total')
                    ->money('HNL')
                    ->sortable()
                    ->weight('bold')
                    ->color('emerald'),

                TextColumn::make('costo_real')
                    ->label('Costo real')
                    ->money('HNL')
                    ->state(fn (Proyecto $record): string => CostoObra::para($record)->costoTotal)
                    ->toggleable()
                    ->visible(fn (mixed $livewire): bool => self::faseEjecucionActiva($livewire)),

                TextColumn::make('margen')
                    ->label('Margen')
                    ->badge()
                    ->color(fn (Proyecto $record): string => bccomp(CostoObra::para($record)->margen, '0', 2) >= 0 ? 'success' : 'danger')
                    ->state(fn (Proyecto $record): string => 'L. '.number_format((float) CostoObra::para($record)->margen, 2).' ('.CostoObra::para($record)->margenPorcentaje.'%)')
                    ->visible(fn (mixed $livewire): bool => self::faseEjecucionActiva($livewire)),

                TextColumn::make('presupuesto_consumido')
                    ->label('Presupuesto')
                    ->badge()
                    ->icon(fn (Proyecto $record): string => CostoObra::para($record)->nivel()->getIcon())
                    ->color(fn (Proyecto $record): string => CostoObra::para($record)->nivel()->getColor())
                    ->state(fn (Proyecto $record): string => CostoObra::para($record)->porcentajeConsumido.'% — '.CostoObra::para($record)->nivel()->getLabel())
                    ->visible(fn (mixed $livewire): bool => self::faseEjecucionActiva($livewire)),

                TextColumn::make('avance_fisico_cache')
                    ->label('Avance obra')
                    ->badge()
                    ->state(fn (Proyecto $record): string => $record->avance_fisico_cache.'%')
                    ->color(fn (Proyecto $record): string => match (true) {
                        (float) $record->avance_fisico_cache >= 100.0 => 'success',
                        $record->estaAtrasado()                       => 'danger',
                        (float) $record->avance_fisico_cache > 0.0    => 'info',
                        default                                       => 'gray',
                    })
                    ->toggleable()
                    ->visible(fn (mixed $livewire): bool => self::faseEjecucionActiva($livewire)),

                TextColumn::make('plazo_restante')
                    ->label('Plazo')
                    ->badge()
                    ->state(function (Proyecto $record): string {
                        if ($record->fecha_inicio === null) {
                            return '—';
                        }

                        $rest = $record->diasRestantes() ?? 0;

                        return $rest >= 0 ? $rest.' días' : abs($rest).' días atraso';
                    })
                    ->color(fn (Proyecto $record): string => $record->estaAtrasado() ? 'danger' : 'gray')
                    ->toggleable()
                    ->visible(fn (mixed $livewire): bool => self::faseEjecucionActiva($livewire)),
            ])
            ->filters([
                SelectFilter::make('zona_id')
                    ->label('Zona')
                    ->options(fn (): array => Zona::activas()->pluck('codigo', 'id')->all())
                    ->preload(),

                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(EstadoProyecto::options()),

                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(TipoProyecto::options()),

                SelectFilter::make('cliente_id')
                    ->label('Cliente')
                    ->relationship('cliente', 'nombre')
                    ->searchable()
                    ->preload(),

                Filter::make('fecha_emision')
                    ->schema([
                        DatePicker::make('emitido_desde')->native(false),
                        DatePicker::make('emitido_hasta')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['emitido_desde'] ?? null, fn (Builder $q, $d): Builder => $q->whereDate('fecha_emision', '>=', $d))
                            ->when($data['emitido_hasta'] ?? null, fn (Builder $q, $d): Builder => $q->whereDate('fecha_emision', '<=', $d));
                    }),
            ])
            ->recordActions([
                ViewAction::make()->label('Costos'),
                EditAction::make(),
                ActionGroup::make([
                    AccionesEjecucion::iniciar(),
                    AccionesEjecucion::registrarAnticipo(),
                    AccionesEjecucion::ajustarPlazo(),
                    AccionesEjecucion::pausar(),
                    AccionesEjecucion::reactivar(),
                    AccionesEjecucion::finalizar(),
                    AccionesEjecucion::cancelar(),
                ])
                    ->label('Ejecución')
                    ->icon('heroicon-o-play-circle')
                    ->color('primary')
                    ->button()
                    ->visible(fn (?Proyecto $record): bool => $record !== null && in_array(
                        $record->estado,
                        [EstadoProyecto::Aprobada, EstadoProyecto::EnEjecucion, EstadoProyecto::Pausada],
                        strict: true,
                    )),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Solo se eliminan Borradores: una cotización enviada o
                    // una obra en ejecución jamás se borra en masa. Coincide
                    // con la regla del DeleteAction individual (EditProyecto).
                    DeleteBulkAction::make()
                        ->modalDescription('Solo se eliminarán los proyectos en estado Borrador. Los demás se omitirán.')
                        ->action(function (Collection $records): void {
                            /** @var Collection<int, Proyecto> $records */
                            [$eliminables, $protegidos] = $records->partition(
                                fn (Proyecto $p): bool => $p->estado === EstadoProyecto::Borrador,
                            );

                            $eliminables->each(fn (Proyecto $p) => $p->delete());

                            if ($protegidos->isNotEmpty()) {
                                Notification::make()
                                    ->warning()
                                    ->title("{$eliminables->count()} borradores eliminados")
                                    ->body("{$protegidos->count()} proyectos no se eliminaron porque ya no son borradores.")
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->success()
                                ->title("{$eliminables->count()} borradores eliminados")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('codigo', 'desc')
            ->paginated([25, 50, 100])
            ->striped()
            ->poll('60s');
    }
}
