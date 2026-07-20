<?php

declare(strict_types=1);

namespace App\Filament\Resources\Planillas\Tables;

use App\Enums\EstadoPlanilla;
use App\Enums\Periodicidad;
use App\Exceptions\Planilla\PlanillaException;
use App\Models\Planilla;
use App\Services\Planilla\ProcesarPlanillaService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PlanillasTable
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
                TextColumn::make('periodicidad')
                    ->label('Periodicidad')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (Periodicidad $state): string => $state->getLabel()),
                TextColumn::make('fecha_inicio')
                    ->label('Desde')
                    ->date('d/M/Y')
                    ->sortable(),
                TextColumn::make('fecha_fin')
                    ->label('Hasta')
                    ->date('d/M/Y')
                    ->sortable(),
                TextColumn::make('lineas_count')
                    ->label('Empleados')
                    ->counts('lineas')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray'),
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (EstadoPlanilla $state): string => $state->getColor())
                    ->icon(fn (EstadoPlanilla $state): string => $state->getIcon())
                    ->formatStateUsing(fn (EstadoPlanilla $state): string => $state->getLabel())
                    ->sortable(),
                TextColumn::make('total_cache')
                    ->label('Total')
                    ->money('HNL')
                    ->weight('bold')
                    ->alignEnd()
                    ->sortable(),
            ])
            ->defaultSort('codigo', 'desc')
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(EstadoPlanilla::options()),
                SelectFilter::make('periodicidad')
                    ->label('Periodicidad')
                    ->options(Periodicidad::options()),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Planilla $record): bool => $record->estado === EstadoPlanilla::Borrador),
                Action::make('cerrar')
                    ->label('Cerrar')
                    ->icon('heroicon-o-lock-closed')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Cierra la planilla: calcula los montos y la hace contar en el costo de las obras. No se puede editar después.')
                    ->visible(fn (Planilla $record): bool => $record->estado === EstadoPlanilla::Borrador)
                    ->action(function (Planilla $record): void {
                        try {
                            app(ProcesarPlanillaService::class)->cerrar($record);
                        } catch (PlanillaException $e) {
                            Notification::make()->title('No se pudo cerrar')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Planilla cerrada')
                            ->body('Los pagos se cargaron al costo de cada obra.')
                            ->success()
                            ->send();
                    }),
                // Recibos de pago (pedido del cliente 2026-07-20): un PDF
                // con el recibo de CADA empleado del período, uno por
                // página, listo para imprimir y firmar. Solo cerradas.
                Action::make('recibos')
                    ->label('Recibos')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->visible(fn (Planilla $record): bool => $record->estado === EstadoPlanilla::Cerrada
                        && (auth()->user()?->can('View:Planilla') ?? false))
                    ->url(fn (Planilla $record): string => route('reportes.recibos-planilla', $record), shouldOpenInNewTab: true),
            ])
            ->paginated([25, 50, 100]);
    }
}
