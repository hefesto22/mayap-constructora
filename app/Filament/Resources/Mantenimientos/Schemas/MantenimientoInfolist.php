<?php

declare(strict_types=1);

namespace App\Filament\Resources\Mantenimientos\Schemas;

use App\Enums\EstadoMantenimiento;
use App\Enums\FaseMantenimiento;
use App\Enums\PrioridadMantenimiento;
use App\Models\MantenimientoMaquina;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Vista del mantenimiento: los datos del evento arriba y, debajo (el
 * relation manager), la bitácora de diagnósticos con fecha y hora.
 */
class MantenimientoInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Mantenimiento')
                ->icon('heroicon-o-wrench-screwdriver')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('codigo')
                            ->label('Código')
                            ->weight('bold')
                            ->copyable(),
                        TextEntry::make('maquina.nombre')
                            ->label('Máquina'),
                        TextEntry::make('estado')
                            ->label('Estado')
                            ->badge()
                            ->color(fn (EstadoMantenimiento $state): string => $state->getColor())
                            ->icon(fn (EstadoMantenimiento $state): string => $state->getIcon())
                            ->formatStateUsing(fn (EstadoMantenimiento $state): string => $state->getLabel()),
                        TextEntry::make('prioridad')
                            ->label('Prioridad de reparación')
                            ->badge()
                            ->color(fn (PrioridadMantenimiento $state): string => $state->getColor())
                            ->icon(fn (PrioridadMantenimiento $state): string => $state->getIcon())
                            ->formatStateUsing(fn (PrioridadMantenimiento $state): string => $state->getLabel())
                            ->visible(fn (MantenimientoMaquina $record): bool => $record->estado === EstadoMantenimiento::EnProceso),
                        TextEntry::make('fase')
                            ->label('Fase de la reparación')
                            ->badge()
                            ->color(fn (FaseMantenimiento $state): string => $state->getColor())
                            ->icon(fn (FaseMantenimiento $state): string => $state->getIcon())
                            ->formatStateUsing(fn (FaseMantenimiento $state): string => $state->getLabel())
                            ->visible(fn (MantenimientoMaquina $record): bool => $record->estado === EstadoMantenimiento::EnProceso),
                        TextEntry::make('fecha_inicio')
                            ->label('Inicio')
                            ->date('d/M/Y'),
                        TextEntry::make('fecha_fin')
                            ->label('Finalizado')
                            ->date('d/M/Y')
                            ->placeholder('En proceso'),
                        TextEntry::make('fecha_estimada_repuestos')
                            ->label('Repuestos estimados para')
                            ->date('d/M/Y')
                            ->placeholder('—')
                            ->color(fn (MantenimientoMaquina $record): string => $record->estado === EstadoMantenimiento::EnProceso
                                && $record->fase->esperaRepuestos()
                                && $record->fecha_estimada_repuestos !== null
                                && $record->fecha_estimada_repuestos->isPast()
                                    ? 'danger'
                                    : 'gray'),
                        TextEntry::make('asignacionSustituta.codigo')
                            ->label('Máquina sustituta')
                            ->badge()
                            ->color('primary')
                            ->placeholder('Sin sustitución'),
                    ]),
                    TextEntry::make('motivo')
                        ->label('Motivo del ingreso')
                        ->columnSpanFull(),
                    TextEntry::make('notas')
                        ->label('Notas')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
