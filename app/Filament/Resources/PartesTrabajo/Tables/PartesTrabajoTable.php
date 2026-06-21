<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartesTrabajo\Tables;

use App\Enums\MetodoCapturaHoras;
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
                TextColumn::make('metodo_captura')
                    ->label('Método')
                    ->badge()
                    ->color(fn (MetodoCapturaHoras $state): string => $state->getColor())
                    ->formatStateUsing(fn (MetodoCapturaHoras $state): string => $state->getLabel()),
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
