<?php

declare(strict_types=1);

namespace App\Services\Proyectos;

use App\Enums\EstadoProyecto;
use App\Enums\ModoPlazo;
use App\Exceptions\Proyectos\DatosEjecucionInvalidosException;
use App\Models\Proyecto;
use App\Support\CalculadorPlazo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Corrige el plazo de una obra ya iniciada (se equivocaron en la fecha de
 * inicio, en los días o en el modo). Recalcula la fecha de fin estimada.
 *
 * Solo durante la ejecución (En ejecución / Pausada): no tiene sentido
 * "ajustar plazo" en una cotización que aún no arrancó (para eso está
 * Iniciar) ni en una obra ya finalizada/cancelada.
 */
final class AjustarPlazoProyectoService
{
    private const array ESTADOS_PERMITIDOS = [
        EstadoProyecto::EnEjecucion,
        EstadoProyecto::Pausada,
    ];

    public function ejecutar(
        Proyecto $proyecto,
        Carbon $fechaInicio,
        int $plazoDias,
        ModoPlazo $modo,
    ): Proyecto {
        if (! in_array($proyecto->estado, self::ESTADOS_PERMITIDOS, strict: true)) {
            throw DatosEjecucionInvalidosException::estadoNoPermiteAjuste($proyecto->estado);
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

            $fresco->forceFill([
                'fecha_inicio'       => $inicio,
                'plazo_dias'         => $plazoDias,
                'modo_plazo'         => $modo,
                'fecha_fin_estimada' => $finEstimada,
            ])->save();

            return $fresco->refresh();
        });
    }
}
