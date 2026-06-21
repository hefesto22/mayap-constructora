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
 * de pago del empleado y el total de la planilla, y la cierra.
 *
 *   - jornal:  monto = días trabajados × tarifa aplicada
 *   - salario: monto = tarifa aplicada (fija del período)
 *   - destajo: monto = el capturado por tarea (no se recalcula)
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
        $planilla->loadMissing('lineas');

        $total = '0';

        foreach ($planilla->lineas as $linea) {
            $monto = $this->montoDeLinea($linea);

            if ($monto !== (string) $linea->monto_bruto) {
                $linea->monto_bruto = $monto;
                $linea->save();
            }

            $total = bcadd($total, $monto, self::SCALE);
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
            TipoPago::Jornal  => bcmul((string) ($linea->dias_trabajados ?? '0'), (string) $linea->tarifa_aplicada, self::SCALE),
            TipoPago::Salario => (string) $linea->tarifa_aplicada,
            // Destajo: el monto lo captura el usuario por tarea; se respeta.
            TipoPago::Destajo => (string) $linea->monto_bruto,
        };
    }
}
