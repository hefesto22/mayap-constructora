<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Exceptions\Maquinaria\MantenimientoPreventivoInvalidoException;
use App\Models\CambioMantenimiento;
use App\Models\PlanMantenimiento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Registra que el cambio de mantenimiento YA se hizo (aceite, puntas,
 * cuchillas...) — la ÚNICA puerta que resetea la línea base del plan:
 *
 *  1. Deja la fila de historial en `cambios_mantenimiento`.
 *  2. Mueve la línea base del plan (fecha/horómetro/km del cambio) →
 *     el contador arranca de cero.
 *  3. Limpia `ultimo_aviso_estado` → la campanita vuelve a avisar en
 *     el próximo ciclo.
 *  4. Si viene lectura de km MÁS RECIENTE que la de la máquina, la
 *     sube también a `maquinas.kilometraje_actual` (el km es manual y
 *     este es su punto natural de captura). El horómetro NO se toca:
 *     ese solo lo mueven los partes de trabajo.
 *
 * Todo en una transacción y con bitácora (log 'maquinaria').
 */
final class RegistrarCambioMantenimientoService
{
    private const int SCALE = 2;

    public function registrar(
        PlanMantenimiento $plan,
        string $fecha,
        ?string $horometro = null,
        ?string $kilometraje = null,
        ?string $notas = null,
        ?int $userId = null,
    ): CambioMantenimiento {
        if (! $plan->activo) {
            throw MantenimientoPreventivoInvalidoException::planInactivo($plan->nombre);
        }

        $dia = Carbon::parse($fecha)->startOfDay();

        if ($dia->isAfter(today())) {
            throw MantenimientoPreventivoInvalidoException::fechaFutura($dia->format('d/m/Y'));
        }

        if ($horometro !== null && bccomp($horometro, '0', self::SCALE) < 0) {
            throw MantenimientoPreventivoInvalidoException::lecturaNegativa('horómetro', $horometro);
        }

        if ($kilometraje !== null && bccomp($kilometraje, '0', self::SCALE) < 0) {
            throw MantenimientoPreventivoInvalidoException::lecturaNegativa('kilometraje', $kilometraje);
        }

        return DB::transaction(function () use ($plan, $dia, $horometro, $kilometraje, $notas, $userId): CambioMantenimiento {
            $planBloqueado = PlanMantenimiento::query()
                ->whereKey($plan->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $cambio = CambioMantenimiento::query()->create([
                'plan_mantenimiento_id' => $planBloqueado->id,
                'fecha'                 => $dia,
                'horometro'             => $horometro,
                'kilometraje'           => $kilometraje,
                'notas'                 => $notas,
                'user_id'               => $userId,
            ]);

            $planBloqueado->forceFill([
                'fecha_ultimo_cambio'     => $dia,
                'horometro_ultimo_cambio' => $horometro ?? $planBloqueado->horometro_ultimo_cambio,
                'km_ultimo_cambio'        => $kilometraje ?? $planBloqueado->km_ultimo_cambio,
                'ultimo_aviso_estado'     => null,
            ])->save();

            $maquina = $planBloqueado->maquina;

            if (
                $kilometraje !== null
                && (
                    $maquina->kilometraje_actual === null
                    || bccomp($kilometraje, (string) $maquina->kilometraje_actual, self::SCALE) > 0
                )
            ) {
                $maquina->forceFill(['kilometraje_actual' => $kilometraje])->save();
            }

            activity('maquinaria')
                ->performedOn($planBloqueado)
                ->causedBy($userId)
                ->withProperties([
                    'fecha'       => $dia->toDateString(),
                    'horometro'   => $horometro,
                    'kilometraje' => $kilometraje,
                    'notas'       => $notas,
                ])
                ->event('cambio_mantenimiento')
                ->log("Cambio realizado: {$planBloqueado->nombre} de {$maquina->codigo}");

            return $cambio;
        });
    }
}
