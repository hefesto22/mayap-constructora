<?php

declare(strict_types=1);

namespace App\Services\Proyectos;

use App\Models\Proyecto;

/**
 * Calcula el porcentaje de avance físico de un proyecto a partir de
 * sus actividades completadas, con ponderación opcional.
 *
 * REGLA DE PONDERACIÓN (peso efectivo de cada actividad):
 *  - peso definido → se usa ese valor.
 *  - peso NULL     → cuenta como 1 (peso uniforme).
 *
 * Así:
 *  - Si NINGUNA actividad tiene peso → avance = completadas / total
 *    (cada una vale lo mismo).
 *  - Si TIENEN peso → avance = Σ peso(completadas) / Σ peso(todas).
 *  - Mixto → las sin peso valen 1 y las demás su peso.
 *
 * avance = (Σ peso completadas / Σ peso todas) × 100, redondeado a 2.
 * Sin actividades → 0.00.
 *
 * bcmath con escala interna 12, redondeo half-up al exponer a 2
 * decimales (mismo patrón que el resto de los calculadores).
 *
 * CQRS:
 *  - calcular(): query pura, no persiste.
 *  - recalcular(): comando, persiste avance_fisico_cache y devuelve fresh.
 */
final class CalcularAvanceProyectoService
{
    private const int SCALE_INTERNO = 12;

    private const int SCALE_FINAL = 2;

    /**
     * Devuelve el % de avance como string con 2 decimales. No persiste.
     */
    public function calcular(Proyecto $proyecto): string
    {
        $proyecto->loadMissing('actividades');

        $pesoTotal = '0';
        $pesoCompletadas = '0';

        foreach ($proyecto->actividades as $actividad) {
            $peso = $actividad->peso !== null ? (string) $actividad->peso : '1';

            $pesoTotal = bcadd($pesoTotal, $peso, self::SCALE_INTERNO);

            if ($actividad->completada) {
                $pesoCompletadas = bcadd($pesoCompletadas, $peso, self::SCALE_INTERNO);
            }
        }

        if (bccomp($pesoTotal, '0', self::SCALE_INTERNO) <= 0) {
            return '0.00';
        }

        $fraccion = bcdiv($pesoCompletadas, $pesoTotal, self::SCALE_INTERNO);
        $porcentajeCrudo = bcmul($fraccion, '100', self::SCALE_INTERNO);

        return $this->bcround($porcentajeCrudo, self::SCALE_FINAL);
    }

    /**
     * Recalcula y persiste el avance en el proyecto. Devuelve fresh.
     */
    public function recalcular(Proyecto $proyecto): Proyecto
    {
        $avance = $this->calcular($proyecto);

        $proyecto->forceFill(['avance_fisico_cache' => $avance])->save();

        return $proyecto->refresh();
    }

    /**
     * Redondeo half-away-from-zero a $scale decimales con bcmath.
     */
    private function bcround(string $value, int $scale): string
    {
        $factor = '0.'.str_repeat('0', $scale).'5';

        return bccomp($value, '0', self::SCALE_INTERNO) >= 0
            ? bcadd($value, $factor, $scale)
            : bcsub($value, $factor, $scale);
    }
}
