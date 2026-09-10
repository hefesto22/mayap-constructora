<?php

declare(strict_types=1);

namespace App\Filament\Resources\Maquinas\Actions;

use App\Enums\EstadoMaquina;
use App\Exceptions\Maquinaria\AsignacionInvalidaException;
use App\Models\Maquina;
use App\Services\Maquinaria\AsignarMaquinaService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;

/**
 * Acción "Liberar de la obra": la salida de una máquina ASIGNADA desde
 * el mismo listado donde se ve el estado (decisión Mauricio 2026-08-16).
 * Gemela de AccionMarcarReparada — antes una máquina "Asignada" no tenía
 * ninguna acción en su fila y la única puerta vivía en Asignaciones, un
 * módulo que gerencia ni siquiera ve.
 *
 * Delega en AsignarMaquinaService::liberarDeObra — cierra la asignación
 * abierta o, si no hay ninguna, libera la máquina dejando constancia.
 */
final class AccionLiberarDeObra
{
    public static function make(): Action
    {
        return Action::make('liberar_de_obra')
            ->label('Liberar de la obra')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('info')
            ->modalHeading('Devolver la máquina al parque')
            ->modalDescription(fn (Maquina $record): string => self::queSeCierra($record))
            ->modalSubmitActionLabel('Liberar')
            ->visible(fn (Maquina $record): bool => $record->estado === EstadoMaquina::Asignada)
            ->schema([
                DatePicker::make('fecha_fin')
                    ->label('Último día en la obra')
                    ->default(now())
                    ->required()
                    ->native(false),
            ])
            ->action(function (Maquina $record, array $data): void {
                try {
                    $asignacion = app(AsignarMaquinaService::class)->liberarDeObra(
                        maquina: $record,
                        fechaFin: isset($data['fecha_fin']) ? (string) $data['fecha_fin'] : null,
                    );
                } catch (AsignacionInvalidaException $e) {
                    Notification::make()
                        ->title('No se pudo liberar')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title("{$record->nombre} quedó disponible")
                    ->body($asignacion !== null
                        ? "Se cerró la asignación {$asignacion->codigo}: ya se puede agendar y asignar a otra obra."
                        : 'No tenía ninguna asignación abierta: se liberó y quedó anotado quién lo hizo.')
                    ->success()
                    ->send();
            });
    }

    /**
     * A qué obra está comprometida y desde cuándo, o el aviso de que el
     * estado quedó sin asignación detrás.
     */
    private static function queSeCierra(Maquina $record): string
    {
        $asignacion = $record->asignacionActiva;

        if ($asignacion === null) {
            return 'Esta máquina figura como asignada pero NO tiene ninguna asignación abierta. '
                .'Al confirmar vuelve a Disponible y queda constancia de quién la liberó.';
        }

        $asignacion->loadMissing('proyecto:id,nombre');

        $dias = (int) $asignacion->fecha_inicio->diffInDays(now());

        return "{$asignacion->codigo} — {$asignacion->proyecto->nombre}. "
            ."En la obra desde el {$asignacion->fecha_inicio->format('d/m/Y')} ({$dias} día(s)). "
            .'Al confirmar se cierra la asignación y la máquina vuelve a Disponible.';
    }
}
