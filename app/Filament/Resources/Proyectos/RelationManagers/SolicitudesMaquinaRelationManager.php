<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\RelationManagers;

use App\Models\SolicitudMaquina;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * Solicitudes de maquinaria del proyecto — parte del historial: quién
 * pidió qué máquina, para cuándo, y en qué quedó (agendada, pendiente o
 * rechazada con motivo). Solo lectura: se crean desde su propio módulo.
 */
class SolicitudesMaquinaRelationManager extends RelationManager
{
    protected static string $relationship = 'solicitudesMaquina';

    protected static ?string $title = 'Solicitudes de maquinaria';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable(),

                TextColumn::make('maquina.nombre')
                    ->label('Máquina'),

                TextColumn::make('fecha_necesaria')
                    ->label('Para')
                    ->formatStateUsing(fn (SolicitudMaquina $record): string => $record->rangoParaEl())
                    ->sortable(),

                TextColumn::make('hora_llegada')
                    ->label('Llegada')
                    ->formatStateUsing(fn (?string $state): string => $state !== null
                        ? Carbon::parse($state)->format('g:i A')
                        : '—'),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge(),

                TextColumn::make('prioridad')
                    ->label('Prioridad')
                    ->badge(),

                TextColumn::make('solicitante.name')
                    ->label('Solicitó')
                    ->placeholder('—'),

                TextColumn::make('motivo')
                    ->label('Resolución')
                    ->limit(40)
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Sin solicitudes de maquinaria')
            ->paginated([25, 50]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
