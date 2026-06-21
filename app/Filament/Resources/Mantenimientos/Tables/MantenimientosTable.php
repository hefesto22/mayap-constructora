<?php

declare(strict_types=1);

namespace App\Filament\Resources\Mantenimientos\Tables;

use App\Enums\EstadoMantenimiento;
use App\Filament\Resources\Mantenimientos\Actions\AccionFinalizarMantenimiento;
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
                TextColumn::make('asignacionSustituta.codigo')
                    ->label('Sustituta')
                    ->badge()
                    ->color('primary')
                    ->placeholder('Sin sustitución'),
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (EstadoMantenimiento $state): string => $state->getColor())
                    ->icon(fn (EstadoMantenimiento $state): string => $state->getIcon())
                    ->formatStateUsing(fn (EstadoMantenimiento $state): string => $state->getLabel())
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
                SelectFilter::make('maquina_id')
                    ->label('Máquina')
                    ->relationship('maquina', 'nombre')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                AccionFinalizarMantenimiento::make(),
            ])
            ->paginated([25, 50, 100]);
    }
}
