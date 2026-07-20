<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Enums\FaseMantenimiento;
use App\Exceptions\Maquinaria\MantenimientoInvalidoException;
use App\Models\BitacoraMantenimiento;
use App\Models\MantenimientoMaquina;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Registra un diagnóstico o avance de fase en un mantenimiento en proceso
 * (decisión Mauricio 2026-07-20). Única puerta que mueve la fase y escribe
 * la bitácora — así el historial siempre queda con fecha, hora y autor.
 *
 * Reglas:
 *  - Solo mantenimientos EN PROCESO (los finalizados son historia).
 *  - La fase puede repetirse (dos diagnósticos seguidos = dos entradas).
 *  - "Compra de repuestos" exige fecha estimada de recepción (si no la
 *    tenía ya); cambiar esa fecha REINICIA la campanita de llegada.
 */
final class RegistrarAvanceMantenimientoService
{
    /**
     * @param string|null $fechaEstimadaRepuestos Cuándo se estima que lleguen
     *                                            los repuestos (fases que esperan repuestos).
     */
    public function avanzar(
        MantenimientoMaquina $mantenimiento,
        FaseMantenimiento $fase,
        string $detalle,
        ?string $fechaEstimadaRepuestos = null,
        ?int $userId = null,
    ): BitacoraMantenimiento {
        return DB::transaction(function () use ($mantenimiento, $fase, $detalle, $fechaEstimadaRepuestos, $userId): BitacoraMantenimiento {
            $bloqueado = MantenimientoMaquina::query()
                ->whereKey($mantenimiento->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $bloqueado->estado->esEnProceso()) {
                throw MantenimientoInvalidoException::noSePuedeAvanzar($bloqueado->codigo);
            }

            // La compra de repuestos sin fecha estimada deja ciega la
            // campanita de llegada: se exige aquí, no solo en el form.
            if ($fase === FaseMantenimiento::CompraRepuestos
                && $fechaEstimadaRepuestos === null
                && $bloqueado->fecha_estimada_repuestos === null) {
                throw MantenimientoInvalidoException::faltaFechaEstimada($bloqueado->codigo);
            }

            $bloqueado->fase = $fase;

            if ($fechaEstimadaRepuestos !== null) {
                $cambio = $bloqueado->fecha_estimada_repuestos === null
                    || ! $bloqueado->fecha_estimada_repuestos->isSameDay($fechaEstimadaRepuestos);

                $bloqueado->fecha_estimada_repuestos = Carbon::parse($fechaEstimadaRepuestos);

                if ($cambio) {
                    // Nueva fecha → rearmar el aviso de llegada.
                    $bloqueado->aviso_repuestos_at = null;
                }
            }

            $bloqueado->save();

            $entrada = BitacoraMantenimiento::create([
                'mantenimiento_maquina_id' => $bloqueado->id,
                'fase'                     => $fase,
                'detalle'                  => $detalle,
                'user_id'                  => $userId,
            ]);

            activity('maquinaria')
                ->performedOn($bloqueado)
                ->withProperties([
                    'fase'            => $fase->value,
                    'fecha_repuestos' => $bloqueado->fecha_estimada_repuestos?->toDateString(),
                ])
                ->event('avance_mantenimiento')
                ->log("Avance en {$bloqueado->codigo}: {$fase->getLabel()}");

            return $entrada;
        });
    }
}
