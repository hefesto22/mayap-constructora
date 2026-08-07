<?php

declare(strict_types=1);

namespace App\Filament\Resources\Requisiciones\Tables;

use App\Enums\EstadoRequisicion;
use App\Filament\Resources\Requisiciones\Actions\AccionesTransicion;
use App\Models\Requisicion;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RequisicionesTable
{
    public static function configure(Table $table): Table
    {
        return $table
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
                AccionesTransicion::despachar(),
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
