<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Enums\PrioridadMantenimiento;
use App\Exceptions\Maquinaria\MantenimientoInvalidoException;
use App\Models\BitacoraMantenimiento;
use App\Models\MantenimientoMaquina;
use Illuminate\Support\Facades\DB;

/**
 * Cambia la prioridad de reparación de un mantenimiento en proceso
 * (decisión Mauricio 2026-07-20): gerencia o recepción marcan cuál
 * máquina es la más importante. Única puerta — deja constancia en la
 * bitácora (fecha, hora y quién) y avisa por campanita al taller.
 */
final class CambiarPrioridadMantenimientoService
{
    public function __construct(private readonly NotificadorMantenimiento $notificador) {}

    public function cambiar(
        MantenimientoMaquina $mantenimiento,
        PrioridadMantenimiento $prioridad,
        ?string $motivo = null,
        ?int $userId = null,
    ): void {
        DB::transaction(function () use ($mantenimiento, $prioridad, $motivo, $userId): void {
            $bloqueado = MantenimientoMaquina::query()
                ->whereKey($mantenimiento->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $bloqueado->estado->esEnProceso()) {
                throw MantenimientoInvalidoException::noSePuedeAvanzar($bloqueado->codigo);
            }

            $anterior = $bloqueado->prioridad;

            if ($anterior === $prioridad) {
                return; // Sin cambio: ni bitácora ni campanita fantasma.
            }

            $bloqueado->prioridad = $prioridad;
            $bloqueado->save();

            BitacoraMantenimiento::create([
                'mantenimiento_maquina_id' => $bloqueado->id,
                'fase'                     => $bloqueado->fase,
                'detalle'                  => "Prioridad de reparación: {$anterior->getLabel()} → {$prioridad->getLabel()}"
                    .($motivo !== null && $motivo !== '' ? " — {$motivo}" : '.'),
                'user_id' => $userId,
            ]);

            activity('maquinaria')
                ->performedOn($bloqueado)
                ->withProperties([
                    'anterior' => $anterior->value,
                    'nueva'    => $prioridad->value,
                ])
                ->event('prioridad_cambiada')
                ->log("Prioridad de {$bloqueado->codigo}: {$prioridad->getLabel()}");

            // Campanita DENTRO de la transacción: rollback = sin avisos.
            $this->notificador->prioridadCambiada($bloqueado, $motivo);
        });
    }
}
