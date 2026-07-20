<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartesTrabajo\Tables;

use App\Enums\MetodoCapturaHoras;
use App\Enums\ModalidadTrabajo;
use App\Models\ParteTrabajo;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PartesTrabajoTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codigo')
                    ->label('Parte')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/M/Y')
                    ->sortable(),
                TextColumn::make('asignacion.maquina.nombre')
                    ->label('Máquina')
                    ->searchable()
                    ->limit(28),
                TextColumn::make('asignacion.proyecto.nombre')
                    ->label('Obra')
                    ->searchable()
                    ->limit(28),
                // Modalidad (2026-07-20): horas, km, viajes o flete.
                TextColumn::make('modalidad')
                    ->label('Modalidad')
                    ->badge()
                    ->color(fn (ModalidadTrabajo $state): string => $state->getColor())
                    ->icon(fn (ModalidadTrabajo $state): string => $state->getIcon())
                    ->formatStateUsing(fn (ModalidadTrabajo $state): string => $state->getLabel())
                    ->toggleable(),
                // El dato de la modalidad: km, viajes con su ruta, o la
                // actividad del flete. Vacío en partes por horas.
                TextColumn::make('trabajo_detalle')
                    ->label('Trabajo')
                    ->state(fn (ParteTrabajo $record): ?string => match ($record->modalidad) {
                        ModalidadTrabajo::Kilometraje => rtrim(rtrim((string) $record->km_recorridos, '0'), '.').' km',
                        ModalidadTrabajo::Viajes      => "{$record->viajes} viaje(s)"
                            .($record->viaje_origen !== null || $record->viaje_destino !== null
                                ? ' · '.($record->viaje_origen ?? '¿?').' → '.($record->viaje_destino ?? '¿?')
                                : ''),
                        ModalidadTrabajo::Flete => $record->actividad,
                        default                 => null,
                    })
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('metodo_captura')
                    ->label('Método')
                    ->badge()
                    ->color(fn (MetodoCapturaHoras $state): string => $state->getColor())
                    ->formatStateUsing(fn (MetodoCapturaHoras $state): string => $state->getLabel())
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('horas')
                    ->label('Horas')
                    ->numeric(2)
                    ->suffix(' h')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('horas_extra')
                    ->label('Extra')
                    ->numeric(2)
                    ->suffix(' h')
                    ->alignEnd()
                    ->color('warning')
                    ->toggleable(),
                TextColumn::make('costo_cache')
                    ->label('Costo')
                    ->money('HNL')
                    ->weight('bold')
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()->money('HNL')->label('Total')),
            ])
            ->defaultSort('fecha', 'desc')
            ->filters([
                SelectFilter::make('modalidad')
                    ->label('Modalidad')
                    ->options(ModalidadTrabajo::options()),
                SelectFilter::make('metodo_captura')
                    ->label('Método')
                    ->options(MetodoCapturaHoras::options()),
                SelectFilter::make('asignacion_maquina_id')
                    ->label('Asignación')
                    ->relationship('asignacion', 'codigo')
                    ->searchable()
                    ->preload(),
            ])
            ->paginated([25, 50, 100]);
    }
}
