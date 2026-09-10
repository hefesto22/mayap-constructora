<?php

declare(strict_types=1);

namespace App\Filament\Resources\Maquinas\Actions;

use App\Enums\EstadoMaquina;
use App\Exceptions\Maquinaria\MantenimientoInvalidoException;
use App\Models\Maquina;
use App\Services\Maquinaria\MantenimientoService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;

/**
 * Acción "Marcar como reparada": la SALIDA del taller desde el mismo
 * listado donde se ve el problema (decisión Mauricio 2026-08-16). Antes
 * una máquina "En mantenimiento" no tenía ninguna acción en su fila y la
 * única puerta vivía en otro módulo, escondida tras el scroll horizontal.
 *
 * Espejo exacto de "Enviar a mantenimiento": misma visibilidad por estado
 * y mismo alcance (quien puede mandarla al taller puede traerla de vuelta).
 * Delega en MantenimientoService::marcarReparada — cierra el expediente
 * abierto con su bitácora o, si no hay ninguno, libera la máquina dejando
 * constancia en el registro de actividad.
 */
final class AccionMarcarReparada
{
    public static function make(): Action
    {
        return Action::make('marcar_reparada')
            ->label('Marcar como reparada')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->modalHeading('Devolver la máquina al parque')
            ->modalDescription(fn (Maquina $record): string => self::queSeCierra($record))
            ->modalSubmitActionLabel('Marcar como reparada')
            ->visible(fn (Maquina $record): bool => $record->estado === EstadoMaquina::Mantenimiento)
            ->schema([
                DatePicker::make('fecha_fin')
                    ->label('Fecha en que terminó la reparación')
                    ->default(now())
                    ->required()
                    ->native(false),
            ])
            ->action(function (Maquina $record, array $data): void {
                $userId = auth()->id();
                $userId = is_numeric($userId) ? (int) $userId : null;

                try {
                    $mantenimiento = app(MantenimientoService::class)->marcarReparada(
                        maquina: $record,
                        fechaFin: isset($data['fecha_fin']) ? (string) $data['fecha_fin'] : null,
                        userId: $userId,
                    );
                } catch (MantenimientoInvalidoException $e) {
                    Notification::make()
                        ->title('No se pudo devolver al parque')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title("{$record->nombre} quedó disponible")
                    ->body($mantenimiento !== null
                        ? "Se cerró la reparación {$mantenimiento->codigo}: ya se puede agendar y asignar a obra."
                        : 'No tenía ninguna reparación abierta: se liberó y quedó anotado quién lo hizo.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Qué se está cerrando exactamente — el expediente abierto con sus
     * días en el taller, o el aviso de que no hay ninguno.
     */
    private static function queSeCierra(Maquina $record): string
    {
        $taller = $record->mantenimientoEnProceso;

        if ($taller === null) {
            return 'Esta máquina figura en el taller pero NO tiene ninguna reparación abierta. '
                .'Al confirmar vuelve a Disponible y queda constancia de quién la liberó.';
        }

        $dias = (int) $taller->fecha_inicio->diffInDays(now());

        return "{$taller->codigo} — {$taller->motivo}. "
            ."En el taller desde el {$taller->fecha_inicio->format('d/m/Y')} ({$dias} día(s)), "
            ."fase: {$taller->fase->getLabel()}. "
            .'Al confirmar se cierra la reparación y la máquina vuelve a Disponible.';
    }
}
