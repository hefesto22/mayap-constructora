<?php

declare(strict_types=1);

namespace App\Filament\Resources\CuentasPorPagar\Tables;

use App\Enums\EstadoCuentaPorPagar;
use App\Filament\Resources\CuentasPorPagar\Actions\AccionAbonar;
use App\Filament\Resources\CuentasPorPagar\Actions\AccionCambiarVencimiento;
use App\Models\CuentaPorPagar;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

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
                    // lt(today()) y NO isPast(): la que vence HOY todavía
                    // no está vencida (mismo corte que la pestaña Vencidas).
                    ->color(fn (CuentaPorPagar $record): string => $record->estado !== EstadoCuentaPorPagar::Pagada
                        && $record->fecha_vencimiento->lt(today())
                            ? 'danger'
                            : 'gray')
                    ->description(fn (CuentaPorPagar $record): ?string => $record->estado !== EstadoCuentaPorPagar::Pagada
                        && $record->fecha_vencimiento->lt(today())
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
                // El filtro "solo vencidas" se retiró: las pestañas
                // Vencidas / Por vencer (2026-07-20) cubren ese corte.
            ])
            ->recordActions([
                ViewAction::make(),
                AccionAbonar::make(),
                AccionCambiarVencimiento::make(),
            ])
            ->paginated([25, 50, 100]);
    }
}
