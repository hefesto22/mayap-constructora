<?php

declare(strict_types=1);

namespace App\Filament\Resources\AsignacionesMaquina\Actions;

use App\Enums\EstadoAsignacion;
use App\Exceptions\Maquinaria\AsignacionInvalidaException;
use App\Models\AsignacionMaquina;
use App\Services\Maquinaria\AsignarMaquinaService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;

/**
 * Acción "Finalizar" una asignación activa. Cierra la asignación y libera la
 * máquina (vuelve a Disponible) vía AsignarMaquinaService. Solo visible
 * mientras la asignación está activa.
 */
final class AccionFinalizar
{
    public static function make(): Action
    {
        return Action::make('finalizar')
            ->label('Finalizar')
            ->icon('heroicon-o-stop-circle')
            ->color('warning')
            ->modalHeading('Finalizar asignación')
            ->modalDescription('Cierra la asignación y libera la máquina. Ya no recibirá partes de trabajo.')
            ->modalSubmitActionLabel('Finalizar asignación')
            ->visible(fn (AsignacionMaquina $record): bool => $record->estado === EstadoAsignacion::Activa)
            ->schema([
                DatePicker::make('fecha_fin')
                    ->label('Fecha de finalización')
                    ->default(now())
                    ->required()
                    ->native(false),
            ])
            ->action(function (AsignacionMaquina $record, array $data): void {
                try {
                    app(AsignarMaquinaService::class)->finalizar(
                        asignacion: $record,
                        fechaFin: isset($data['fecha_fin']) ? (string) $data['fecha_fin'] : null,
                    );
                } catch (AsignacionInvalidaException $e) {
                    Notification::make()
                        ->title('No se pudo finalizar')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Asignación finalizada')
                    ->body('La máquina quedó disponible para una nueva obra.')
                    ->success()
                    ->send();
            });
    }
}
