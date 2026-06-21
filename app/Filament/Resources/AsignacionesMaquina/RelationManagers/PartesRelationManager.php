<?php

declare(strict_types=1);

namespace App\Filament\Resources\AsignacionesMaquina\RelationManagers;

use App\Enums\MetodoCapturaHoras;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Partes de trabajo de una asignación — solo lectura. Muestra las horas
 * registradas y su costo. Los partes se crean con la acción "Registrar parte"
 * (RegistrarParteService), nunca a mano.
 */
class PartesRelationManager extends RelationManager
{
    protected static string $relationship = 'partes';

    protected static ?string $title = 'Partes de trabajo';

    protected static string|BackedEnum|null $icon = 'heroicon-o-clock';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codigo')
                    ->label('Parte')
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/M/Y')
                    ->sortable(),
                TextColumn::make('metodo_captura')
                    ->label('Método')
                    ->badge()
                    ->color(fn (MetodoCapturaHoras $state): string => $state->getColor())
                    ->icon(fn (MetodoCapturaHoras $state): string => $state->getIcon())
                    ->formatStateUsing(fn (MetodoCapturaHoras $state): string => $state->getLabel()),
                TextColumn::make('horas')
                    ->label('Horas')
                    ->numeric(2)
                    ->suffix(' h')
                    ->alignEnd(),
                TextColumn::make('horas_extra')
                    ->label('Extra')
                    ->numeric(2)
                    ->suffix(' h')
                    ->alignEnd()
                    ->color('warning')
                    ->placeholder('—'),
                TextColumn::make('operador')
                    ->label('Operador')
                    ->placeholder('—')
                    ->limit(25),
                TextColumn::make('costo_cache')
                    ->label('Costo')
                    ->money('HNL')
                    ->weight('bold')
                    ->alignEnd()
                    ->summarize(Sum::make()->money('HNL')->label('Total cobrado a la obra')),
            ])
            ->defaultSort('fecha', 'desc')
            ->paginated([10, 25, 50]);
    }
}
