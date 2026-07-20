<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use App\Enums\UnidadRenta;
use App\Exceptions\Proyectos\RentaInvalidaException;
use App\Models\ParteTrabajo;
use App\Models\Proyecto;
use App\Models\ProyectoLineaRenta;

/**
 * Resumen PACTADO vs REAL de la maquinaria de una renta (decisión
 * Mauricio 2026-07-20) — los números que alimentan el Excel:
 *
 * Por cada máquina y DIMENSIÓN de cobro (horas/días, viajes o km,
 * decisión 2026-07-20: cada tipo trabaja distinto): lo cotizado, lo
 * real según los PARTES DE TRABAJO (la única verdad), la diferencia y
 * el extra facturable — MISMAS REGLAS que FinalizarRentaService (se
 * cobra lo pactado como mínimo; trabajar menos no descuenta):
 *
 *   extra = max(0, real − pactado) × tarifa de la unidad (líneas por
 *           día → tarifa / jornada), SIN ISV.
 *
 * Si cambia la regla de cobro, cambiar AMBOS servicios.
 */
final class ResumenRentaService
{
    private const int SCALE = 2;

    /**
     * @return array{
     *     filas: list<array{maquina: string, unidad: string, detalle: string, tarifa: string, pactado_cant: string, real_cant: string, diferencia: string, pactado: string, extra: string}>,
     *     total_pactado: string,
     *     total_extra: string,
     * }
     */
    public function resumen(Proyecto $proyecto): array
    {
        if (! $proyecto->esRenta()) {
            throw RentaInvalidaException::noEsRenta($proyecto->codigo);
        }

        $proyecto->loadMissing('lineasRenta.maquina');

        $filas = [];
        $totalPactado = '0.00';
        $totalExtra = '0.00';

        foreach ($proyecto->lineasRenta->groupBy('maquina_id') as $maquinaId => $lineas) {
            $primera = $lineas->first();

            if ($primera === null) {
                continue;
            }

            $maquina = $primera->maquina;

            foreach ($lineas->groupBy(fn (ProyectoLineaRenta $l): string => $l->unidad->dimension()) as $dimension => $lineasDim) {
                $pactadoCant = '0.00';
                $pactadoMonto = '0.00';
                $partesDetalle = [];

                foreach ($lineasDim as $linea) {
                    $pactadoCant = bcadd(
                        $pactadoCant,
                        $dimension === 'horas' ? $linea->horasPactadas() : (string) $linea->cantidad,
                        self::SCALE,
                    );
                    $pactadoMonto = bcadd($pactadoMonto, (string) $linea->subtotal_cache, self::SCALE);

                    $partesDetalle[] = rtrim(rtrim((string) $linea->cantidad, '0'), '.')
                        .' '.mb_strtoupper($linea->unidad->getLabel())
                        .' × L '.number_format((float) $linea->tarifa_snapshot, 2)
                        .($linea->es_extension ? ' (EXTENSIÓN)' : '');
                }

                $real = $this->realDeMaquina($proyecto->id, (int) $maquinaId, (string) $dimension);

                $diferencia = bcsub($real, $pactadoCant, self::SCALE);

                $ultimaLinea = $lineasDim->sortByDesc('id')->first();
                $tarifa = $this->tarifaUnitaria((string) $dimension, $ultimaLinea);

                $extra = bccomp($diferencia, '0', self::SCALE) > 0
                    ? bcadd(bcmul($diferencia, $tarifa, 4), '0.005', self::SCALE)
                    : '0.00';

                $filas[] = [
                    'maquina'      => "{$maquina->codigo} — {$maquina->nombre}",
                    'unidad'       => $this->etiquetaDimension((string) $dimension),
                    'detalle'      => implode(' + ', $partesDetalle),
                    'tarifa'       => $tarifa,
                    'pactado_cant' => $pactadoCant,
                    'real_cant'    => $real,
                    'diferencia'   => $diferencia,
                    'pactado'      => $pactadoMonto,
                    'extra'        => $extra,
                ];

                $totalPactado = bcadd($totalPactado, $pactadoMonto, self::SCALE);
                $totalExtra = bcadd($totalExtra, $extra, self::SCALE);
            }
        }

        return [
            'filas'         => $filas,
            'total_pactado' => $totalPactado,
            'total_extra'   => $totalExtra,
        ];
    }

    /**
     * Lo REAL de una máquina en el proyecto según sus partes: horas
     * (horas + extra), viajes o km — según la dimensión.
     */
    private function realDeMaquina(int $proyectoId, int $maquinaId, string $dimension): string
    {
        $partes = ParteTrabajo::query()
            ->whereHas('asignacion', static function ($query) use ($proyectoId, $maquinaId): void {
                $query->where('proyecto_id', $proyectoId)
                    ->where('maquina_id', $maquinaId);
            })
            ->get(['horas', 'horas_extra', 'viajes', 'km_recorridos']);

        $total = '0.00';

        foreach ($partes as $parte) {
            $total = match ($dimension) {
                'viajes' => bcadd($total, (string) ($parte->viajes ?? 0), self::SCALE),
                'km'     => bcadd($total, (string) ($parte->km_recorridos ?? '0'), self::SCALE),
                default  => bcadd(bcadd($total, (string) $parte->horas, self::SCALE), (string) $parte->horas_extra, self::SCALE),
            };
        }

        return $total;
    }

    /**
     * Tarifa unitaria de la dimensión: horas → tarifa horaria (por día
     * ÷ jornada); viajes/km → el snapshot de la línea más reciente.
     */
    private function tarifaUnitaria(string $dimension, ?ProyectoLineaRenta $linea): string
    {
        if ($linea === null) {
            return '0.00';
        }

        if ($dimension !== 'horas') {
            return (string) $linea->tarifa_snapshot;
        }

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

    private function etiquetaDimension(string $dimension): string
    {
        return match ($dimension) {
            'viajes' => 'Viajes',
            'km'     => 'Kilómetros',
            default  => 'Horas',
        };
    }
}
