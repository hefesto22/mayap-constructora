<?php

declare(strict_types=1);

namespace App\Filament\Resources\AsignacionesMaquina\Tables;

use App\Enums\EstadoAsignacion;
use App\Filament\Resources\AsignacionesMaquina\Actions\AccionFinalizar;
use App\Filament\Resources\AsignacionesMaquina\Actions\AccionRegistrarCombustible;
use App\Filament\Resources\AsignacionesMaquina\Actions\AccionRegistrarParte;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AsignacionesMaquinaTable
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
                TextColumn::make('proyecto.nombre')
                    ->label('Obra')
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                TextColumn::make('tarifa_hora_pactada')
                    ->label('Tarifa/h')
                    ->money('HNL')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (EstadoAsignacion $state): string => $state->getColor())
                    ->icon(fn (EstadoAsignacion $state): string => $state->getIcon())
                    ->formatStateUsing(fn (EstadoAsignacion $state): string => $state->getLabel())
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
                    ->options(EstadoAsignacion::options()),
                SelectFilter::make('proyecto_id')
                    ->label('Obra')
                    ->relationship('proyecto', 'nombre')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('maquina_id')
                    ->label('Máquina')
                    ->relationship('maquina', 'nombre')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    AccionRegistrarParte::make(),
                    AccionRegistrarCombustible::make(),
                    AccionFinalizar::make(),
                ]),
            ])
            ->paginated([25, 50, 100]);
    }
}
