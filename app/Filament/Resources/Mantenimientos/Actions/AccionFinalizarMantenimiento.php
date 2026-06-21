<?php

declare(strict_types=1);

namespace App\Filament\Resources\Mantenimientos\Actions;

use App\Enums\EstadoMantenimiento;
use App\Exceptions\Maquinaria\MantenimientoInvalidoException;
use App\Models\MantenimientoMaquina;
use App\Services\Maquinaria\MantenimientoService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;

/**
 * Acción "Finalizar mantenimiento". Cierra la reparación y devuelve la máquina
 * a disponible vía MantenimientoService. Solo visible mientras está en proceso.
 */
final class AccionFinalizarMantenimiento
{
    public static function make(): Action
    {
        return Action::make('finalizar_mantenimiento')
            ->label('Finalizar')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->modalHeading('Finalizar mantenimiento')
            ->modalDescription('Marca la reparación como terminada y devuelve la máquina a disponible.')
            ->modalSubmitActionLabel('Finalizar')
            ->visible(fn (MantenimientoMaquina $record): bool => $record->estado === EstadoMantenimiento::EnProceso)
            ->schema([
                DatePicker::make('fecha_fin')
                    ->label('Fecha de finalización')
                    ->default(now())
                    ->required()
                    ->native(false),
            ])
            ->action(function (MantenimientoMaquina $record, array $data): void {
                try {
                    app(MantenimientoService::class)->finalizar(
                        mantenimiento: $record,
                        fechaFin: isset($data['fecha_fin']) ? (string) $data['fecha_fin'] : null,
                    );
                } catch (MantenimientoInvalidoException $e) {
                    Notification::make()
                        ->title('No se pudo finalizar')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Mantenimiento finalizado')
                    ->body('La máquina quedó disponible.')
                    ->success()
                    ->send();
            });
    }
}
