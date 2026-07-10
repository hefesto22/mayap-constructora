<?php

declare(strict_types=1);

namespace App\Services\Proyectos;

use App\Enums\EstadoProyecto;
use App\Exceptions\Proyectos\DatosEjecucionInvalidosException;
use App\Exceptions\Proyectos\TransicionEstadoInvalidaException;
use App\Models\Proyecto;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gestiona los cambios de estado de la fase de ejecución de un
 * proyecto: pausar, reactivar, finalizar y cancelar.
 *
 * Reglas:
 *  - Pausar y cancelar EXIGEN motivo (se persiste y queda en el log).
 *  - Finalizar y cancelar fijan fecha_fin_real (recortada para nunca
 *    quedar antes de la fecha de inicio — defensa del CHECK constraint
 *    de coherencia de fechas).
 *  - Toda transición valida la máquina de estados (EstadoProyecto) y
 *    corre en transacción con lockForUpdate.
 *
 * El cambio de estado y motivos quedan auditados automáticamente por
 * el trait LogsActivity del modelo (logOnlyDirty incluye estos campos).
 */
final class CambiarEstadoEjecucionService
{
    /**
     * En ejecución → Pausada. Requiere motivo.
     */
    public function pausar(Proyecto $proyecto, string $motivo): Proyecto
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw DatosEjecucionInvalidosException::motivoRequerido('pausar');
        }

        return $this->transicionar(
            $proyecto,
            EstadoProyecto::Pausada,
            static fn (): array => ['motivo_pausa' => $motivo],
        );
    }

    /**
     * Pausada → En ejecución. Limpia el motivo de pausa vigente.
     */
    public function reactivar(Proyecto $proyecto): Proyecto
    {
        return $this->transicionar(
            $proyecto,
            EstadoProyecto::EnEjecucion,
            static fn (): array => ['motivo_pausa' => null],
        );
    }

    /**
     * En ejecución / Pausada → Finalizada. Fija fecha_fin_real.
     */
    public function finalizar(Proyecto $proyecto, ?Carbon $fechaFinReal = null): Proyecto
    {
        $fecha = $fechaFinReal?->copy()->startOfDay();

        return $this->transicionar(
            $proyecto,
            EstadoProyecto::Finalizada,
            static function (Proyecto $fresco) use ($fecha): array {
                $fin = $fecha ?? Carbon::today();

                if ($fresco->fecha_inicio !== null && $fin->lessThan($fresco->fecha_inicio)) {
                    $fin = $fresco->fecha_inicio->copy();
                }

                return ['fecha_fin_real' => $fin];
            },
        );
    }

    /**
     * Aprobada / En ejecución / Pausada → Cancelada. Requiere motivo.
     * Si la obra ya había arrancado, fija fecha_fin_real = hoy.
     */
    public function cancelar(Proyecto $proyecto, string $motivo): Proyecto
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw DatosEjecucionInvalidosException::motivoRequerido('cancelar');
        }

        return $this->transicionar(
            $proyecto,
            EstadoProyecto::Cancelada,
            static function (Proyecto $fresco) use ($motivo): array {
                $extra = ['motivo_cancelacion' => $motivo];

                if ($fresco->fecha_inicio !== null) {
                    $fin = Carbon::today();

                    if ($fin->lessThan($fresco->fecha_inicio)) {
                        $fin = $fresco->fecha_inicio->copy();
                    }

                    $extra['fecha_fin_real'] = $fin;
                }

                return $extra;
            },
        );
    }

    /**
     * Aplica la transición bajo lock: valida la máquina de estados y
     * persiste el estado destino + los campos extra calculados.
     *
     * @param Closure(Proyecto): array<string, mixed> $extras
     */
    private function transicionar(Proyecto $proyecto, EstadoProyecto $destino, Closure $extras): Proyecto
    {
        return DB::transaction(function () use ($proyecto, $destino, $extras): Proyecto {
            $fresco = Proyecto::query()
                ->lockForUpdate()
                ->findOrFail($proyecto->id);

            if (! $fresco->estado->puedeTransicionarA($destino)) {
                throw new TransicionEstadoInvalidaException(
                    $fresco->codigo,
                    $fresco->estado,
                    $destino,
                );
            }

            $fresco->forceFill([
                'estado' => $destino,
                ...$extras($fresco),
            ])->save();

            return $fresco->refresh();
        });
    }
}
