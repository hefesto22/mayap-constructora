<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras\Tables;

use App\Enums\CondicionPago;
use App\Enums\EstadoCompra;
use App\Exceptions\Compras\CompraException;
use App\Models\Compra;
use App\Services\Compras\ConfirmarCompraService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ComprasTable
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
                TextColumn::make('proveedor.nombre')
                    ->label('Proveedor')
                    ->searchable()
                    ->sortable()
                    ->limit(35),
                TextColumn::make('bodega.codigo')
                    ->label('Bodega')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (EstadoCompra $state): string => $state->getColor())
                    ->icon(fn (EstadoCompra $state): string => $state->getIcon())
                    ->formatStateUsing(fn (EstadoCompra $state): string => $state->getLabel())
                    ->sortable(),
                TextColumn::make('condicion_pago')
                    ->label('Pago')
                    ->badge()
                    ->color(fn (CondicionPago $state): string => $state->getColor())
                    ->formatStateUsing(fn (CondicionPago $state): string => $state->getLabel()),
                TextColumn::make('total_cache')
                    ->label('Total')
                    ->money('HNL')
                    ->weight('bold')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/M/Y')
                    ->sortable(),
            ])
            ->defaultSort('codigo', 'desc')
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(EstadoCompra::options()),
                SelectFilter::make('proveedor_id')
                    ->label('Proveedor')
                    ->relationship('proveedor', 'nombre')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Compra $record): bool => $record->estado === EstadoCompra::Borrador),
                Action::make('confirmar')
                    ->label('Confirmar')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Confirma la compra: registra el stock en la bodega con su costo (promedio ponderado) y marca la compra como confirmada. No se puede deshacer.')
                    ->visible(fn (Compra $record): bool => $record->estado === EstadoCompra::Borrador)
                    ->action(function (Compra $record): void {
                        $userId = auth()->id();
                        $userId = is_numeric($userId) ? (int) $userId : null;

                        try {
                            app(ConfirmarCompraService::class)->confirmar($record, $userId);
                        } catch (CompraException $e) {
                            Notification::make()->title('No se pudo confirmar')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Compra confirmada')
                            ->body('El stock entró a la bodega con su costo promedio.')
                            ->success()
                            ->send();
                    }),
            ])
            ->paginated([25, 50, 100]);
    }
}
