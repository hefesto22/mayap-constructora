<?php

declare(strict_types=1);

namespace App\Services\Proyectos;

use App\Enums\EstadoProyecto;
use App\Enums\ModoPlazo;
use App\Exceptions\Proyectos\DatosEjecucionInvalidosException;
use App\Exceptions\Proyectos\TransicionEstadoInvalidaException;
use App\Models\Proyecto;
use App\Support\CalculadorPlazo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Arranca la ejecución de un proyecto aprobado.
 *
 * Define la fecha de inicio (el reloj del plazo empieza a correr
 * desde aquí), el plazo en días y el modo (calendario u hábiles).
 * Calcula la fecha de fin estimada y mueve el estado a "En ejecución".
 *
 * Solo procede si el proyecto está Aprobado (única transición válida
 * hacia En ejecución). Va en transacción con lockForUpdate para
 * serializar cambios de estado concurrentes.
 */
final class IniciarProyectoService
{
    public function ejecutar(
        Proyecto $proyecto,
        Carbon $fechaInicio,
        int $plazoDias,
        ModoPlazo $modo,
    ): Proyecto {
        if (! $proyecto->estado->puedeTransicionarA(EstadoProyecto::EnEjecucion)) {
            throw new TransicionEstadoInvalidaException(
                $proyecto->codigo,
                $proyecto->estado,
                EstadoProyecto::EnEjecucion,
            );
        }

        if ($plazoDias < 1) {
            throw DatosEjecucionInvalidosException::plazoInvalido($plazoDias);
        }

        $inicio = $fechaInicio->copy()->startOfDay();
        $finEstimada = CalculadorPlazo::calcularFechaFin($inicio, $plazoDias, $modo);

        return DB::transaction(function () use ($proyecto, $inicio, $plazoDias, $modo, $finEstimada): Proyecto {
            $fresco = Proyecto::query()
                ->lockForUpdate()
                ->findOrFail($proyecto->id);

            // Re-chequeo dentro del lock por si cambió entre la lectura y aquí.
            if (! $fresco->estado->puedeTransicionarA(EstadoProyecto::EnEjecucion)) {
                throw new TransicionEstadoInvalidaException(
                    $fresco->codigo,
                    $fresco->estado,
                    EstadoProyecto::EnEjecucion,
                );
            }

            $fresco->forceFill([
                'estado'             => EstadoProyecto::EnEjecucion,
                'fecha_inicio'       => $inicio,
                'plazo_dias'         => $plazoDias,
                'modo_plazo'         => $modo,
                'fecha_fin_estimada' => $finEstimada,
            ])->save();

            return $fresco->refresh();
        });
    }
}
