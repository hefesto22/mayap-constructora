<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ModoPlazo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Calcula la fecha de fin estimada de una obra a partir de su fecha
 * de inicio, el plazo en días y el modo (calendario u hábiles).
 *
 * Convención adoptada (contratos de obra HN):
 *  - Calendario: fecha_fin = inicio + N días corridos.
 *      Ej: inicio lunes 1, plazo 5 → fin sábado 6.
 *  - Hábiles: se avanzan N días hábiles desde el inicio, saltando
 *    sábados y domingos.
 *      Ej: inicio lunes 1, plazo 5 → fin lunes 8 (mar/mié/jue/vie/lun).
 *
 * PHP puro, sin dependencias de Laravel salvo Carbon (fechas). No
 * toca base de datos. Determinístico.
 *
 * DEUDA EXPLÍCITA: el modo hábiles aún no descuenta feriados
 * nacionales de Honduras. Cuando se necesite, inyectar aquí una
 * lista de feriados (config/honduras.php) y saltarlos igual que
 * los fines de semana.
 */
final class CalculadorPlazo
{
    /**
     * Devuelve la fecha de fin estimada (solo fecha, sin hora).
     *
     * @param int $dias Plazo en días. Debe ser >= 1.
     */
    public static function calcularFechaFin(Carbon $inicio, int $dias, ModoPlazo $modo): Carbon
    {
        if ($dias < 1) {
            throw new InvalidArgumentException("El plazo debe ser al menos 1 día. Recibido: {$dias}.");
        }

        $base = $inicio->copy()->startOfDay();

        return match ($modo) {
            ModoPlazo::Calendario => $base->copy()->addDays($dias),
            ModoPlazo::Habiles    => self::avanzarDiasHabiles($base, $dias),
        };
    }

    /**
     * Avanza $dias días hábiles desde $inicio, saltando sábados y
     * domingos. El día de inicio NO cuenta como hábil consumido; se
     * cuentan los días hábiles transcurridos hacia adelante.
     */
    private static function avanzarDiasHabiles(Carbon $inicio, int $dias): Carbon
    {
        $fecha = $inicio->copy();
        $habilesContados = 0;

        while ($habilesContados < $dias) {
            $fecha->addDay();

            if (! $fecha->isWeekend()) {
                $habilesContados++;
            }
        }

        return $fecha;
    }
}
