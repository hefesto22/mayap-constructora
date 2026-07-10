<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Models\AsignacionMaquina;
use App\Models\ConsumoCombustible;
use App\Models\MantenimientoMaquina;
use App\Models\Maquina;
use App\Models\ParteTrabajo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Hoja de vida de la máquina (G5): junta lo que el sistema ya captura —
 * partes de trabajo, combustible, asignaciones y mantenimientos — en el
 * expediente completo con su rentabilidad. Todo por agregados en SQL:
 * escala con años de historia sin cargar filas a memoria.
 */
final class HojaDeVidaMaquinaService
{
    private const int SCALE = 2;

    public function resumen(Maquina $maquina): ResumenMaquina
    {
        $partes = $this->partesDe($maquina);

        $ingresos = (string) $partes->sum('costo_cache');
        $horas = bcadd(
            (string) $this->partesDe($maquina)->sum('horas'),
            (string) $this->partesDe($maquina)->sum('horas_extra'),
            self::SCALE,
        );

        $consumos = $this->consumosDe($maquina);
        $combustible = (string) $consumos->sum('costo_cache');
        $litros = (string) $this->consumosDe($maquina)->sum('cantidad_litros');

        $utilidad = bcsub($ingresos, $combustible, self::SCALE);

        $margen = bccomp($ingresos, '0', self::SCALE) > 0
            ? bcmul(bcdiv($utilidad, $ingresos, 6), '100', self::SCALE)
            : '0.00';

        return new ResumenMaquina(
            horas: $horas,
            ingresos: $ingresos,
            combustible: $combustible,
            litros: $litros,
            utilidad: $utilidad,
            margen: $margen,
            totalAsignaciones: AsignacionMaquina::query()->where('maquina_id', $maquina->id)->count(),
            totalMantenimientos: MantenimientoMaquina::query()->where('maquina_id', $maquina->id)->count(),
        );
    }

    /**
     * Historial de asignaciones con sus totales (horas, ingreso y
     * combustible por obra) — agregados en SQL vía withSum.
     *
     * @return Collection<int, AsignacionMaquina>
     */
    public function asignacionesConTotales(Maquina $maquina): Collection
    {
        return AsignacionMaquina::query()
            ->where('maquina_id', $maquina->id)
            ->with('proyecto:id,codigo,nombre')
            ->withSum('partes as ingresos_total', 'costo_cache')
            ->withSum('partes as horas_total', 'horas')
            ->withSum('partes as horas_extra_total', 'horas_extra')
            ->withSum('consumos as combustible_total', 'costo_cache')
            ->orderByDesc('fecha_inicio')
            ->get();
    }

    /**
     * @return Collection<int, MantenimientoMaquina>
     */
    public function mantenimientos(Maquina $maquina): Collection
    {
        return MantenimientoMaquina::query()
            ->where('maquina_id', $maquina->id)
            ->orderByDesc('fecha_inicio')
            ->get();
    }

    /**
     * @return Builder<ParteTrabajo>
     */
    private function partesDe(Maquina $maquina): Builder
    {
        return ParteTrabajo::query()->whereIn(
            'asignacion_maquina_id',
            AsignacionMaquina::query()->where('maquina_id', $maquina->id)->select('id'),
        );
    }

    /**
     * @return Builder<ConsumoCombustible>
     */
    private function consumosDe(Maquina $maquina): Builder
    {
        return ConsumoCombustible::query()->whereIn(
            'asignacion_maquina_id',
            AsignacionMaquina::query()->where('maquina_id', $maquina->id)->select('id'),
        );
    }
}
