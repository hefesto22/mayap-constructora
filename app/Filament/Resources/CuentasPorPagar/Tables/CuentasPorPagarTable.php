<?php

declare(strict_types=1);

namespace App\Filament\Resources\CuentasPorPagar\Tables;

use App\Enums\EstadoCuentaPorPagar;
use App\Filament\Resources\CuentasPorPagar\Actions\AccionAbonar;
use App\Models\CuentaPorPagar;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CuentasPorPagarTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('compra.codigo')
                    ->label('Compra')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('proveedor.nombre')
                    ->label('Proveedor')
                    ->searchable()
                    ->sortable()
                    ->limit(35),
                TextColumn::make('monto_original')
                    ->label('Monto original')
                    ->money('HNL')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('saldo')
                    ->label('Saldo')
                    ->money('HNL')
                    ->weight('bold')
                    ->color(fn (CuentaPorPagar $record): string => $record->estado->getColor())
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (EstadoCuentaPorPagar $state): string => $state->getColor())
                    ->icon(fn (EstadoCuentaPorPagar $state): string => $state->getIcon())
                    ->formatStateUsing(fn (EstadoCuentaPorPagar $state): string => $state->getLabel())
                    ->sortable(),
                TextColumn::make('fecha_vencimiento')
                    ->label('Vence')
                    ->date('d/M/Y')
                    ->sortable()
                    // Resalta en rojo si está vencida y aún tiene saldo.
                    ->color(fn (CuentaPorPagar $record): string => $record->estado !== EstadoCuentaPorPagar::Pagada
                        && $record->fecha_vencimiento->isPast()
                            ? 'danger'
                            : 'gray')
                    ->description(fn (CuentaPorPagar $record): ?string => $record->estado !== EstadoCuentaPorPagar::Pagada
                        && $record->fecha_vencimiento->isPast()
                            ? 'Vencida'
                            : null),
            ])
            ->defaultSort('fecha_vencimiento', 'asc')
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(EstadoCuentaPorPagar::options()),
                SelectFilter::make('proveedor_id')
                    ->label('Proveedor')
                    ->relationship('proveedor', 'nombre')
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
                AccionAbonar::make(),
            ])
            ->paginated([25, 50, 100]);
    }
}
