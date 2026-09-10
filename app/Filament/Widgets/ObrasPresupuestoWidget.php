<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\EstadoProyecto;
use App\Enums\NivelPresupuesto;
use App\Filament\Support\CostoObra;
use App\Models\Proyecto;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Override;

/**
 * Resumen de obras según el consumo de su presupuesto. Es la alerta que pidió
 * el dueño: ver de un vistazo cuántas obras están en riesgo (>=80%) o ya
 * sobregiradas, para reaccionar antes de perder el margen.
 *
 * Solo considera obras con presupuesto cargado (subtotal_cache > 0).
 */
class ObrasPresupuestoWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Presupuesto de obras';

    /**
     * @return array<int, Stat>
     */
    #[Override]
    protected function getStats(): array
    {
        $sano = 0;
        $riesgo = 0;
        $sobregirado = 0;

        // Obras VIVAS con presupuesto (2026-08-16): sin el filtro de
        // estado el KPI contaba cotizaciones nunca aprobadas y obras
        // cerradas — "Obras sanas: 43" con 3 obras reales.
        Proyecto::query()
            ->whereIn('estado', [EstadoProyecto::EnEjecucion->value, EstadoProyecto::Pausada->value])
            ->where('subtotal_cache', '>', 0)
            ->get()
            ->each(function (Proyecto $proyecto) use (&$sano, &$riesgo, &$sobregirado): void {
                match (CostoObra::para($proyecto)->nivel()) {
                    NivelPresupuesto::Sano        => $sano++,
                    NivelPresupuesto::EnRiesgo    => $riesgo++,
                    NivelPresupuesto::Sobregirado => $sobregirado++,
                };
            });

        return [
            Stat::make('Obras sanas', (string) $sano)
                ->description('Menos del 80% del presupuesto')
                ->descriptionIcon('heroicon-o-check-circle')
                ->color('success'),

            Stat::make('En riesgo', (string) $riesgo)
                ->description('Entre 80% y 100% consumido')
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color('warning'),

            Stat::make('Sobregiradas', (string) $sobregirado)
                ->description('El costo ya superó el presupuesto')
                ->descriptionIcon('heroicon-o-fire')
                ->color('danger'),
        ];
    }
}
