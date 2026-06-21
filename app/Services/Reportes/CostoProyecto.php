<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use App\Enums\NivelPresupuesto;

/**
 * Desglose del costo real de una obra frente a su presupuesto de venta.
 *
 * Todos los montos son strings numéricos en HNL (decimal:2). El margen es
 * presupuesto de venta − costo total; el porcentaje es sobre el presupuesto.
 * `porcentajeConsumido` es costo total / presupuesto × 100 y alimenta el
 * nivel de alerta de presupuesto.
 */
final readonly class CostoProyecto
{
    public function __construct(
        public int $proyectoId,
        public string $presupuesto,
        public string $costoMateriales,
        public string $costoMaquinaria,
        public string $costoManoObra,
        public string $costoTotal,
        public string $margen,
        public string $margenPorcentaje,
        public string $porcentajeConsumido,
    ) {}

    /**
     * Nivel de alerta del presupuesto (sano / en riesgo / sobregirado).
     */
    public function nivel(): NivelPresupuesto
    {
        return NivelPresupuesto::desdePorcentaje($this->porcentajeConsumido);
    }
}
