<?php

declare(strict_types=1);

namespace App\Services\Planilla;

use App\Enums\EstadoPlanilla;
use App\Enums\TipoPago;
use App\Exceptions\Planilla\PlanillaNoEditableException;
use App\Models\Planilla;
use App\Models\PlanillaLinea;
use Illuminate\Support\Facades\DB;

/**
 * Procesa una planilla: recalcula el monto bruto de cada línea según el tipo
 * de pago del empleado, las retenciones y el neto, y el total de la planilla.
 *
 *   - jornal:     bruto = días trabajados × tarifa aplicada
 *   - salario:    bruto = tarifa aplicada (fija del período)
 *   - destajo:    bruto = el capturado por tarea (no se recalcula)
 *   - honorarios: bruto = tarifa aplicada, con RETENCIÓN (12.5% sugerido)
 *
 *   neto = bruto − retención − deducciones (adelantos, etc.)
 *
 * El COSTO de mano de obra de las obras es el BRUTO (lo que cuesta el
 * trabajo); la retención es plata que se aparta para el fisco, no un menor
 * costo. El total_cache de la planilla sigue siendo la suma de brutos.
 *
 * Una planilla cerrada cuenta en el costo de mano de obra de cada obra. El
 * cierre es la puerta que confirma el pago; en borrador no impacta costos.
 */
final class ProcesarPlanillaService
{
    private const int SCALE = 2;

    /**
     * Recalcula los montos de las líneas y el total (sin cerrar). Útil para
     * mantener el cache mientras la planilla está en borrador.
     */
    public function recalcular(Planilla $planilla): void
    {
        $planilla->loadMissing('lineas.empleado:id,nombre');

        $total = '0';

        foreach ($planilla->lineas as $linea) {
            $bruto = $this->montoDeLinea($linea);
            $porcentaje = $this->porcentajeRetencion($linea);
            $retencion = $porcentaje === null
                ? '0.00'
                : bcdiv(bcmul($bruto, $porcentaje, 4), '100', self::SCALE);
            $deducciones = (string) ($linea->deducciones ?? '0');
            $neto = bcsub(bcsub($bruto, $retencion, self::SCALE), $deducciones, self::SCALE);

            if (bccomp($neto, '0', self::SCALE) < 0) {
                throw PlanillaNoEditableException::deduccionesExcedenBruto(
                    $planilla->codigo,
                    $linea->empleado->nombre,
                );
            }

            $linea->forceFill([
                'monto_bruto'          => $bruto,
                'retencion_porcentaje' => $porcentaje,
                'retencion_monto'      => $retencion,
                'monto_neto'           => $neto,
            ])->save();

            $total = bcadd($total, $bruto, self::SCALE);
        }

        $planilla->total_cache = $total;
        $planilla->save();
    }

    /**
     * Cierra la planilla: recalcula y la marca como Cerrada (cuenta en costos).
     */
    public function cerrar(Planilla $planilla): void
    {
        if (! $planilla->estado->permiteEditar()) {
            throw PlanillaNoEditableException::noEsBorrador($planilla->codigo, $planilla->estado);
        }

        $planilla->loadMissing('lineas');

        if ($planilla->lineas->isEmpty()) {
            throw PlanillaNoEditableException::sinLineas($planilla->codigo);
        }

        DB::transaction(function () use ($planilla): void {
            $this->recalcular($planilla);

            $planilla->estado = EstadoPlanilla::Cerrada;
            $planilla->save();
        });
    }

    /**
     * Monto bruto de una línea según el tipo de pago.
     */
    private function montoDeLinea(PlanillaLinea $linea): string
    {
        return match ($linea->tipo_pago) {
            TipoPago::Jornal => bcmul((string) ($linea->dias_trabajados ?? '0'), (string) $linea->tarifa_aplicada, self::SCALE),
            TipoPago::Salario,
            TipoPago::Honorarios => (string) $linea->tarifa_aplicada,
            // Destajo: el monto lo captura el usuario por tarea; se respeta.
            TipoPago::Destajo => (string) $linea->monto_bruto,
        };
    }

    /**
     * Porcentaje de retención de la línea: el capturado manda; si no hay
     * y el tipo lo sugiere (honorarios → 12.5%), se aplica el sugerido.
     */
    private function porcentajeRetencion(PlanillaLinea $linea): ?string
    {
        if ($linea->retencion_porcentaje !== null) {
            return (string) $linea->retencion_porcentaje;
        }

        return $linea->tipo_pago->retencionSugerida();
    }
}
