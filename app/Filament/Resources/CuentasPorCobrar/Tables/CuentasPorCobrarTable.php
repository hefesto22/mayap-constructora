<?php

declare(strict_types=1);

namespace App\Filament\Resources\CuentasPorCobrar\Tables;

use App\Enums\EstadoCuentaPorCobrar;
use App\Filament\Resources\CuentasPorCobrar\Actions\AccionCobrar;
use App\Models\CuentaPorCobrar;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CuentasPorCobrarTable
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
                TextColumn::make('cliente.nombre')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                TextColumn::make('proyecto.nombre')
                    ->label('Obra')
                    ->placeholder('—')
                    ->limit(25)
                    ->toggleable(),
                TextColumn::make('monto_original')
                    ->label('Monto')
                    ->money('HNL')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('saldo')
                    ->label('Saldo')
                    ->money('HNL')
                    ->weight('bold')
                    ->color(fn (CuentaPorCobrar $record): string => $record->estado->getColor())
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (EstadoCuentaPorCobrar $state): string => $state->getColor())
                    ->icon(fn (EstadoCuentaPorCobrar $state): string => $state->getIcon())
                    ->formatStateUsing(fn (EstadoCuentaPorCobrar $state): string => $state->getLabel())
                    ->sortable(),
                TextColumn::make('fecha_vencimiento')
                    ->label('Vence')
                    ->date('d/M/Y')
                    ->sortable()
                    ->color(fn (CuentaPorCobrar $record): string => $record->estado !== EstadoCuentaPorCobrar::Pagada
                        && $record->fecha_vencimiento->isPast()
                            ? 'danger'
                            : 'gray')
                    ->description(fn (CuentaPorCobrar $record): ?string => $record->estado !== EstadoCuentaPorCobrar::Pagada
                        && $record->fecha_vencimiento->isPast()
                            ? 'Vencida'
                            : null),
            ])
            ->defaultSort('fecha_vencimiento', 'asc')
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(EstadoCuentaPorCobrar::options()),
                SelectFilter::make('cliente_id')
                    ->label('Cliente')
                    ->relationship('cliente', 'nombre')
                    ->searchable()
                    ->preload(),
                Filter::make('vencidas')
                    ->label('Solo vencidas con saldo')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('saldo', '>', 0)
                        ->whereDate('fecha_vencimiento', '<', now())),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (CuentaPorCobrar $record): bool => $record->estado === EstadoCuentaPorCobrar::Pendiente),
                AccionCobrar::make(),
            ])
            ->paginated([25, 50, 100]);
    }
}
