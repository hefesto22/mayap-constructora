<?php

declare(strict_types=1);

namespace App\Filament\Resources\AsignacionesMaquina\Schemas;

use App\Enums\EstadoAsignacion;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AsignacionMaquinaInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Asignación')
                ->icon('heroicon-o-truck')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('codigo')
                            ->label('Código')
                            ->weight('bold')
                            ->copyable(),
                        TextEntry::make('maquina.nombre')
                            ->label('Máquina'),
                        TextEntry::make('proyecto.nombre')
                            ->label('Obra'),
                        TextEntry::make('tarifa_hora_pactada')
                            ->label('Tarifa por hora')
                            ->money('HNL'),
                        TextEntry::make('estado')
                            ->label('Estado')
                            ->badge()
                            ->color(fn (EstadoAsignacion $state): string => $state->getColor())
                            ->icon(fn (EstadoAsignacion $state): string => $state->getIcon())
                            ->formatStateUsing(fn (EstadoAsignacion $state): string => $state->getLabel()),
                        TextEntry::make('fecha_inicio')
                            ->label('Inicio')
                            ->date('d/M/Y'),
                        TextEntry::make('fecha_fin')
                            ->label('Fin')
                            ->date('d/M/Y')
                            ->placeholder('— (en curso)'),
                        TextEntry::make('notas')
                            ->label('Notas')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
                ]),
        ]);
    }
}
