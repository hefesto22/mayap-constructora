<?php

declare(strict_types=1);

namespace App\Services\Proyectos;

use App\Models\Proyecto;
use Illuminate\Support\Facades\DB;

/**
 * Calculadora de totales del proyecto/cotización.
 *
 * Toma el `subtotal_cache` de cada renglón del proyecto, los suma para
 * obtener el subtotal global, aplica ISV (cuando corresponde), y
 * persiste los totales en el proyecto.
 *
 * FÓRMULAS:
 *
 *   subtotal_proyecto = SUMA(renglones.subtotal_cache)
 *
 *   isv_monto = aplica_isv
 *             ? subtotal × isv_porcentaje / 100
 *             : 0
 *
 *   total = subtotal + isv_monto
 *
 * PRECISIÓN: usa bcmath con scale=12 internamente y redondeo half-up
 * a 2 decimales solo al persistir. Esto evita errores acumulados de
 * coma flotante con muchos renglones.
 *
 * IDEMPOTENCIA: ejecutar dos veces seguidas produce el mismo resultado.
 * El campo `precio_calculado_at` se actualiza en cada llamada para
 * que el indicador "cache desactualizado" se reinicie.
 *
 * TRANSACCIÓN: la lectura de renglones + escritura del cache va dentro
 * de DB::transaction para consistencia bajo concurrencia.
 */
final class CalcularPrecioProyectoService
{
    /** Scale interno para cálculos intermedios con bcmath. */
    private const int SCALE_INTERNO = 12;

    /** Scale final para presentación (centavos HNL). */
    private const int SCALE_FINAL = 2;

    /**
     * Recalcula y persiste los totales del proyecto.
     *
     * Retorna el proyecto refrescado con los totales actualizados.
     */
    public function recalcular(Proyecto $proyecto): Proyecto
    {
        return DB::transaction(function () use ($proyecto): Proyecto {
            $proyecto->loadMissing('renglones');

            $subtotalCrudo = $this->sumarSubtotales($proyecto);
            $isvCrudo = $this->calcularIsv($proyecto, $subtotalCrudo);
            $totalCrudo = bcadd($subtotalCrudo, $isvCrudo, self::SCALE_INTERNO);

            $proyecto->forceFill([
                'subtotal_cache'      => $this->bcround($subtotalCrudo, self::SCALE_FINAL),
                'isv_cache'           => $this->bcround($isvCrudo, self::SCALE_FINAL),
                'total_cache'         => $this->bcround($totalCrudo, self::SCALE_FINAL),
                'precio_calculado_at' => now(),
            ])->save();

            return $proyecto->fresh() ?? $proyecto;
        });
    }

    /**
     * Calcula los totales SIN persistir. Útil para previsualizaciones
     * en formularios o reportes en tiempo real.
     *
     * @return array{
     *     subtotal: string,
     *     isv: string,
     *     total: string,
     * } Valores ya redondeados a 2 decimales.
     */
    public function previsualizar(Proyecto $proyecto): array
    {
        $proyecto->loadMissing('renglones');

        $subtotalCrudo = $this->sumarSubtotales($proyecto);
        $isvCrudo = $this->calcularIsv($proyecto, $subtotalCrudo);
        $totalCrudo = bcadd($subtotalCrudo, $isvCrudo, self::SCALE_INTERNO);

        return [
            'subtotal' => $this->bcround($subtotalCrudo, self::SCALE_FINAL),
            'isv'      => $this->bcround($isvCrudo, self::SCALE_FINAL),
            'total'    => $this->bcround($totalCrudo, self::SCALE_FINAL),
        ];
    }

    /**
     * Suma los subtotales de los renglones (valores ya en bcmath strings).
     * Retorna string crudo a scale=12 para encadenar más operaciones sin
     * pérdida de precisión.
     */
    private function sumarSubtotales(Proyecto $proyecto): string
    {
        $acumulado = '0';

        foreach ($proyecto->renglones as $renglon) {
            $acumulado = bcadd(
                $acumulado,
                (string) $renglon->subtotal_cache,
                self::SCALE_INTERNO,
            );
        }

        return $acumulado;
    }

    /**
     * Calcula el ISV crudo. Si el proyecto NO aplica ISV, retorna '0'.
     *
     * El isv_porcentaje del proyecto se respeta tal cual (puede ser
     * 15% estándar, 0% para exentos, u otro valor si la tasa cambia).
     */
    private function calcularIsv(Proyecto $proyecto, string $subtotalCrudo): string
    {
        if (! $proyecto->aplica_isv) {
            return '0';
        }

        $tasa = (string) $proyecto->isv_porcentaje;
        $factor = bcdiv($tasa, '100', self::SCALE_INTERNO);

        return bcmul($subtotalCrudo, $factor, self::SCALE_INTERNO);
    }

    /**
     * Redondeo half-away-from-zero con bcmath. bcadd trunca, así que
     * sumamos un factor 0.5 en la última posición antes de truncar
     * para emular el modo de redondeo estándar.
     */
    private function bcround(string $value, int $scale): string
    {
        $factor = '0.'.str_repeat('0', $scale).'5';

        if (bccomp($value, '0', self::SCALE_INTERNO) >= 0) {
            return bcadd($value, $factor, $scale);
        }

        return bcsub($value, $factor, $scale);
    }
}
