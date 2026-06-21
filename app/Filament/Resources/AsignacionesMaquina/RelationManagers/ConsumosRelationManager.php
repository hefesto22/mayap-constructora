<?php

declare(strict_types=1);

namespace App\Filament\Resources\AsignacionesMaquina\RelationManagers;

use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Consumos de combustible de una asignación — solo lectura. Los consumos se
 * crean con la acción "Registrar combustible" (RegistrarConsumoCombustible
 * Service), nunca a mano.
 */
class ConsumosRelationManager extends RelationManager
{
    protected static string $relationship = 'consumos';

    protected static ?string $title = 'Combustible';

    protected static string|BackedEnum|null $icon = 'heroicon-o-beaker';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codigo')
                    ->label('Consumo')
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/M/Y')
                    ->sortable(),
                TextColumn::make('cantidad_litros')
                    ->label('Litros')
                    ->numeric(2)
                    ->suffix(' L')
                    ->alignEnd(),
                TextColumn::make('precio_litro')
                    ->label('Precio/L')
                    ->money('HNL')
                    ->alignEnd(),
                TextColumn::make('operador')
                    ->label('Operador')
                    ->placeholder('—')
                    ->limit(25),
                TextColumn::make('costo_cache')
                    ->label('Costo')
                    ->money('HNL')
                    ->weight('bold')
                    ->alignEnd()
                    ->summarize(Sum::make()->money('HNL')->label('Total combustible')),
            ])
            ->defaultSort('fecha', 'desc')
            ->paginated([10, 25, 50]);
    }
}
