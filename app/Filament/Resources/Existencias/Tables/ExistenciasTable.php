<?php

declare(strict_types=1);

namespace App\Filament\Resources\Existencias\Tables;

use App\Models\Bodega;
use App\Models\Existencia;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExistenciasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('material.codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('material.nombre')
                    ->label('Material')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('ubicacion')
                    ->label('Ubicación')
                    ->badge()
                    ->color(fn (Existencia $record): string => $record->bodega_id !== null ? 'info' : 'warning')
                    ->state(fn (Existencia $record): string => $record->bodega_id !== null
                        ? 'Bodega '.$record->bodega->codigo
                        : 'Obra '.$record->proyecto->codigo),
                TextColumn::make('cantidad')
                    ->label('Cantidad')
                    ->numeric(decimalPlaces: 4)
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('material.unidadMedida.simbolo')
                    ->label('Unidad')
                    ->placeholder('—'),
                TextColumn::make('costo_promedio')
                    ->label('Costo promedio')
                    ->money('HNL')
                    ->alignEnd(),
                TextColumn::make('valor_total')
                    ->label('Valor total')
                    ->money('HNL')
                    ->weight('bold')
                    ->alignEnd()
                    ->sortable(),
            ])
            ->defaultSort('valor_total', 'desc')
            ->filters([
                SelectFilter::make('bodega_id')
                    ->label('Bodega')
                    ->options(fn (): array => Bodega::query()
                        ->orderBy('nombre')
                        ->pluck('nombre', 'id')
                        ->all()),
                Filter::make('solo_con_stock')
                    ->label('Solo con stock disponible')
                    ->query(fn (Builder $query): Builder => $query->where('cantidad', '>', 0))
                    ->toggle(),
                Filter::make('en_bodega')
                    ->label('Solo en bodega (no en obra)')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('bodega_id'))
                    ->toggle(),
            ])
            ->poll('60s');
    }
}
