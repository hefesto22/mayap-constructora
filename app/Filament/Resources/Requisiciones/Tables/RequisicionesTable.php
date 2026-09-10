<?php

declare(strict_types=1);

namespace App\Filament\Resources\Requisiciones\Tables;

use App\Enums\EstadoRequisicion;
use App\Enums\ResolucionLinea;
use App\Filament\Resources\Requisiciones\Actions\AccionesTransicion;
use App\Models\Requisicion;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RequisicionesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount([
                'lineas as no_llegan_count' => fn ($q) => $q
                    ->where('resolucion', ResolucionLinea::NoDisponible->value),
            ]))
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('proyecto.nombre')
                    ->label('Obra')
                    ->searchable()
                    ->sortable()
                    ->limit(35),
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (EstadoRequisicion $state): string => $state->getColor())
                    ->icon(fn (EstadoRequisicion $state, Requisicion $record): string => self::esEntregaDelProveedor($record)
                        ? 'heroicon-o-shopping-cart'
                        : $state->getIcon())
                    // "Despachada" en una compra directa confunde al
                    // bodeguero: él no despachó nada, no hubo salida de
                    // inventario suya. Mismo estado, etiqueta honesta.
                    ->formatStateUsing(fn (EstadoRequisicion $state, Requisicion $record): string => self::esEntregaDelProveedor($record)
                        ? 'Entregada por el proveedor'
                        : $state->getLabel())
                    ->sortable(),
                TextColumn::make('lineas_count')
                    ->label('Items')
                    ->counts('lineas')
                    ->badge()
                    ->color('gray'),
                // "Ese no te llega" (Mauricio 2026-09-10): la peor noticia
                // de un pedido no puede estar escondida adentro. Se cuenta
                // en la misma consulta — nada de una query por fila.
                TextColumn::make('no_llegan_count')
                    ->label('No llegan')
                    ->badge()
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->tooltip('Materiales que no se consiguieron: a la obra no le van a llegar.')
                    // Cero no se pinta: un badge rojo con "0" asusta sin
                    // motivo. Sin nada que reportar, la celda va vacía.
                    ->formatStateUsing(fn (mixed $state): ?string => is_numeric($state) && (int) $state > 0
                        ? (string) $state
                        : null)
                    ->placeholder('—'),
                TextColumn::make('fecha_necesaria')
                    ->label('Necesaria')
                    ->date('d/M/Y')
                    ->sortable()
                    // Vencida = ANTES de hoy (mismo criterio que el bloqueo
                    // de Autorizar): la fecha de HOY aún no es un atraso.
                    ->color(fn (Requisicion $record): string => $record->fechaNecesariaVencida()
                        && ! $record->estado->esTerminal() ? 'danger' : 'gray'),
                // Semáforo de la entrega del proveedor (2026-08-07): la obra
                // ve de un vistazo si su material llega a tiempo o no.
                //   verde = llega antes o el mismo día que se necesitaba
                //   ámbar = llega DESPUÉS de la fecha necesaria
                //   rojo  = la fecha prometida ya pasó y sigue sin llegar
                TextColumn::make('fecha_estimada_llegada')
                    ->label('Llega')
                    ->date('d/M/Y')
                    ->placeholder('—')
                    ->sortable()
                    ->color(fn (Requisicion $record): string => match (true) {
                        $record->llegadaVencida() => 'danger',
                        $record->llegaTarde()     => 'warning',
                        default                   => 'success',
                    })
                    ->tooltip(fn (Requisicion $record): ?string => match (true) {
                        $record->llegadaVencida()   => 'El proveedor no ha entregado: la fecha prometida ya pasó.',
                        $record->llegaTarde()       => 'Llega DESPUÉS de la fecha en que se necesitaba en obra.',
                        $record->esperandoLlegada() => 'Fecha de entrega prometida por el proveedor. Si el material llega antes, avisale a recepción para que reprograme la llegada.',
                        default                     => null,
                    }),
                TextColumn::make('solicitante.name')
                    ->label('Solicitante')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Creada')
                    ->dateTime('d/M/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('codigo', 'desc')
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(EstadoRequisicion::options()),
                SelectFilter::make('proyecto_id')
                    ->label('Obra')
                    ->relationship('proyecto', 'nombre')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (Requisicion $record): bool => $record->estado->permiteEditarLineas()),
                AccionesTransicion::autorizar(),
                AccionesTransicion::reprogramar(),
                AccionesTransicion::registrarEntrada(),
                AccionesTransicion::verificarLlegada(),
                AccionesTransicion::revisarPedido(),
                AccionesTransicion::marcarEnTransito(),
                AccionesTransicion::recibir(),
                AccionesTransicion::conciliar(),
                AccionesTransicion::rechazar(),
            ])
            ->paginated([25, 50, 100])
            ->poll('60s');
    }

    /**
     * ¿Esta requisición está "despachada" porque el proveedor la entregó
     * directo en la obra? (no hubo salida de bodega).
     */
    private static function esEntregaDelProveedor(Requisicion $record): bool
    {
        return $record->estado === EstadoRequisicion::Despachada
            && $record->esDespachoDirecto();
    }
}
