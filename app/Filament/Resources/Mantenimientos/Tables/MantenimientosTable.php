<?php

declare(strict_types=1);

namespace App\Filament\Resources\Mantenimientos\Tables;

use App\Enums\EstadoMantenimiento;
use App\Enums\FaseMantenimiento;
use App\Enums\PrioridadMantenimiento;
use App\Filament\Resources\Mantenimientos\Actions\AccionCambiarPrioridad;
use App\Filament\Resources\Mantenimientos\Actions\AccionFinalizarMantenimiento;
use App\Filament\Resources\Mantenimientos\Actions\AccionRegistrarAvance;
use App\Models\MantenimientoMaquina;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MantenimientosTable
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
                TextColumn::make('maquina.nombre')
                    ->label('Máquina')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-o-truck')
                    ->limit(30),
                TextColumn::make('motivo')
                    ->label('Motivo')
                    ->limit(40)
                    ->wrap(),
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (EstadoMantenimiento $state): string => $state->getColor())
                    ->icon(fn (EstadoMantenimiento $state): string => $state->getIcon())
                    ->formatStateUsing(fn (EstadoMantenimiento $state): string => $state->getLabel())
                    ->sortable(),
                // Prioridad de reparación (gerencia/recepción la marcan):
                // en finalizados ya no aporta — se muestra vacía.
                TextColumn::make('prioridad')
                    ->label('Prioridad')
                    ->badge()
                    ->state(fn (MantenimientoMaquina $record): ?PrioridadMantenimiento => $record->estado === EstadoMantenimiento::EnProceso
                        ? $record->prioridad
                        : null)
                    ->color(fn (?PrioridadMantenimiento $state): string => $state?->getColor() ?? 'gray')
                    ->icon(fn (?PrioridadMantenimiento $state): ?string => $state?->getIcon())
                    ->formatStateUsing(fn (?PrioridadMantenimiento $state): string => $state?->getLabel() ?? '—')
                    ->placeholder('—')
                    ->sortable(),
                // Fase de la reparación — solo aporta mientras está en
                // proceso; en finalizados se muestra vacía.
                TextColumn::make('fase')
                    ->label('Fase')
                    ->badge()
                    ->state(fn (MantenimientoMaquina $record): ?FaseMantenimiento => $record->estado === EstadoMantenimiento::EnProceso
                        ? $record->fase
                        : null)
                    ->color(fn (?FaseMantenimiento $state): string => $state?->getColor() ?? 'gray')
                    ->icon(fn (?FaseMantenimiento $state): ?string => $state?->getIcon())
                    ->formatStateUsing(fn (?FaseMantenimiento $state): string => $state?->getLabel() ?? '—')
                    ->placeholder('—'),
                // Fecha estimada de recepción de repuestos: en rojo si ya
                // pasó y la reparación sigue esperándolos.
                TextColumn::make('fecha_estimada_repuestos')
                    ->label('Repuestos')
                    ->date('d/M/Y')
                    ->placeholder('—')
                    ->color(fn (MantenimientoMaquina $record): string => $record->estado === EstadoMantenimiento::EnProceso
                        && $record->fase->esperaRepuestos()
                        && $record->fecha_estimada_repuestos !== null
                        && $record->fecha_estimada_repuestos->isPast()
                            ? 'danger'
                            : 'gray')
                    ->sortable(),
                TextColumn::make('fecha_inicio')
                    ->label('Inicio')
                    ->date('d/M/Y')
                    ->sortable(),
                TextColumn::make('fecha_fin')
                    ->label('Fin')
                    ->date('d/M/Y')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('fecha_inicio', 'desc')
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(EstadoMantenimiento::options()),
                SelectFilter::make('prioridad')
                    ->label('Prioridad')
                    ->options(PrioridadMantenimiento::options()),
                SelectFilter::make('fase')
                    ->label('Fase')
                    ->options(FaseMantenimiento::options()),
                SelectFilter::make('maquina_id')
                    ->label('Máquina')
                    ->relationship('maquina', 'nombre')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                AccionRegistrarAvance::make(),
                AccionCambiarPrioridad::make(),
                AccionFinalizarMantenimiento::make(),
            ])
            ->paginated([25, 50, 100]);
    }
}
