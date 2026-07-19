<?php

declare(strict_types=1);

namespace App\Services\Proyectos;

use App\Enums\UnidadRenta;
use App\Exceptions\Proyectos\RentaInvalidaException;
use App\Models\CuentaPorCobrar;
use App\Models\ParteTrabajo;
use App\Models\Proyecto;
use App\Models\ProyectoLineaRenta;
use App\Services\Cobranza\AjustarCuentaPorCobrarService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Finaliza una renta de maquinaria aplicando la regla de cobro pactada
 * con el cliente: SE COBRA LO COTIZADO COMO MÍNIMO; si las horas
 * reales de los partes superan las pactadas, EL EXTRA SE SUMA a la
 * cuenta por cobrar.
 *
 * Cálculo del extra, POR MÁQUINA (cada una tiene su tarifa):
 *
 *   horas pactadas  = Σ líneas de esa máquina (días → horas por jornada)
 *   horas reales    = Σ partes de trabajo (horas + extra) de esa
 *                     máquina en este proyecto — el parte es la única
 *                     verdad de horas trabajadas
 *   extra           = max(0, reales − pactadas) × tarifa horaria de la
 *                     línea más reciente (por día → tarifa/jornada)
 *
 * Al total del extra se le aplica el ISV del proyecto (si aplica) y se
 * sube a la CxC vía AjustarCuentaPorCobrarService (bitácora incluida).
 * Trabajar MENOS que lo pactado no descuenta nada: ese es el mínimo.
 *
 * La transición de estado la hace CambiarEstadoEjecucionService (misma
 * máquina de estados que cualquier proyecto).
 */
final class FinalizarRentaService
{
    private const int SCALE = 2;

    private const int SCALE_INTERNO = 6;

    public function __construct(
        private readonly CambiarEstadoEjecucionService $estados,
        private readonly AjustarCuentaPorCobrarService $ajustes,
    ) {}

    /**
     * @return array{proyecto: Proyecto, extra: numeric-string, detalle: list<array{maquina: string, pactadas: string, reales: string, extra: string}>}
     */
    public function finalizar(Proyecto $proyecto, ?Carbon $fechaFinReal = null, ?int $userId = null): array
    {
        if (! $proyecto->esRenta()) {
            throw RentaInvalidaException::noEsRenta($proyecto->codigo);
        }

        return DB::transaction(function () use ($proyecto, $fechaFinReal, $userId): array {
            $fresco = $this->estados->finalizar($proyecto, $fechaFinReal);

            [$extraTotal, $detalle] = $this->calcularExtra($fresco);

            if (bccomp($extraTotal, '0', self::SCALE) > 0) {
                $this->cobrarExtra($fresco, $extraTotal, $detalle, $userId);
            }

            return [
                'proyecto' => $fresco,
                'extra'    => $extraTotal,
                'detalle'  => $detalle,
            ];
        });
    }

    /**
     * Extra por máquina: horas reales de los partes vs pactadas en las
     * líneas. Devuelve [total con ISV, detalle por máquina sin ISV].
     *
     * @return array{0: numeric-string, 1: list<array{maquina: string, pactadas: string, reales: string, extra: string}>}
     */
    private function calcularExtra(Proyecto $proyecto): array
    {
        $proyecto->loadMissing('lineasRenta.maquina');

        $porMaquina = $proyecto->lineasRenta->groupBy('maquina_id');

        $extraTotal = '0';
        $detalle = [];

        foreach ($porMaquina as $maquinaId => $lineas) {
            $maquina = $lineas->first()->maquina;

            $pactadas = '0';

            foreach ($lineas as $linea) {
                $pactadas = bcadd($pactadas, $linea->horasPactadas(), self::SCALE);
            }

            $reales = $this->horasRealesDeMaquina($proyecto->id, (int) $maquinaId);

            $horasExtra = bccomp($reales, $pactadas, self::SCALE) > 0
                ? bcsub($reales, $pactadas, self::SCALE)
                : '0.00';

            $extraMaquina = '0.00';

            if (bccomp($horasExtra, '0', self::SCALE) > 0) {
                $tarifaHora = $this->tarifaHorariaVigente($lineas->sortByDesc('id')->first());
                $extraMaquina = bcadd(bcmul($horasExtra, $tarifaHora, 4), '0.005', self::SCALE);
                $extraTotal = bcadd($extraTotal, $extraMaquina, self::SCALE);
            }

            $detalle[] = [
                'maquina'  => $maquina->nombre,
                'pactadas' => $pactadas,
                'reales'   => $reales,
                'extra'    => $extraMaquina,
            ];
        }

        // El extra es venta gravada igual que la renta: mismo ISV.
        if ($proyecto->aplica_isv && bccomp($extraTotal, '0', self::SCALE) > 0) {
            $factor = bcadd('1', bcdiv((string) $proyecto->isv_porcentaje, '100', self::SCALE_INTERNO), self::SCALE_INTERNO);
            $extraTotal = bcadd(bcmul($extraTotal, $factor, 4), '0.005', self::SCALE);
        }

        /** @var numeric-string $extraTotal */
        return [$extraTotal, $detalle];
    }

    /**
     * Horas reales (normales + extra) de una máquina en el proyecto,
     * según sus partes de trabajo — la única verdad de horas.
     */
    private function horasRealesDeMaquina(int $proyectoId, int $maquinaId): string
    {
        $partes = ParteTrabajo::query()
            ->whereHas('asignacion', static function ($query) use ($proyectoId, $maquinaId): void {
                $query->where('proyecto_id', $proyectoId)
                    ->where('maquina_id', $maquinaId);
            })
            ->get(['horas', 'horas_extra']);

        $total = '0';

        foreach ($partes as $parte) {
            $total = bcadd($total, (string) $parte->horas, self::SCALE);
            $total = bcadd($total, (string) $parte->horas_extra, self::SCALE);
        }

        return $total;
    }

    /**
     * Tarifa POR HORA de la línea más reciente de la máquina: por hora
     * es directa; por día se divide entre la jornada de la máquina.
     */
    private function tarifaHorariaVigente(ProyectoLineaRenta $linea): string
    {
        if ($linea->unidad === UnidadRenta::Hora) {
            return (string) $linea->tarifa_snapshot;
        }

        $linea->loadMissing('maquina');

        $jornada = (string) $linea->maquina->jornada_horas;

        if (bccomp($jornada, '0', self::SCALE) <= 0) {
            return (string) $linea->tarifa_snapshot;
        }

        return bcdiv((string) $linea->tarifa_snapshot, $jornada, self::SCALE);
    }

    /**
     * Sube el extra a la CxC de la renta con el detalle en bitácora.
     *
     * @param list<array{maquina: string, pactadas: string, reales: string, extra: string}> $detalle
     */
    private function cobrarExtra(Proyecto $proyecto, string $extraTotal, array $detalle, ?int $userId): void
    {
        $cuenta = CuentaPorCobrar::query()
            ->where('proyecto_id', $proyecto->id)
            ->latest('id')
            ->first();

        if ($cuenta === null) {
            throw RentaInvalidaException::sinCuentaPorCobrar($proyecto->codigo);
        }

        $this->ajustes->aumentar(
            $cuenta,
            $extraTotal,
            'HORAS EXTRA AL FINALIZAR RENTA '.$proyecto->codigo,
            $userId,
        );

        activity('renta')
            ->performedOn($proyecto)
            ->causedBy($userId)
            ->withProperties([
                'extra_total' => $extraTotal,
                'detalle'     => $detalle,
            ])
            ->event('extra_cobrado')
            ->log("Renta {$proyecto->codigo}: extra de L {$extraTotal} por horas reales sobre lo pactado");
    }
}
