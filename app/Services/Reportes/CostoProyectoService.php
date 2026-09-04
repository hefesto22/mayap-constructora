<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use App\Enums\EstadoPlanilla;
use App\Enums\TipoMovimientoInventario;
use App\Models\ConsumoCombustible;
use App\Models\MovimientoInventario;
use App\Models\ParteTrabajo;
use App\Models\PlanillaLinea;
use App\Models\Proyecto;
use Illuminate\Database\Eloquent\Builder;

/**
 * Calcula el costo real de una obra juntando las tres fuentes de costo:
 *
 *  1. Materiales: valor despachado de bodega a la obra (se imputa al despachar,
 *     ver ADR-0002 §2), menos las devoluciones de la obra a bodega.
 *  2. Maquinaria: horas trabajadas (partes) + combustible, vía las asignaciones
 *     de la obra.
 *  3. Mano de obra: 0 por ahora (pendiente del módulo de planilla, Fase D).
 *
 * Compara el costo total con el presupuesto de venta (subtotal sin ISV) y
 * expone el margen. Es una capa de SOLO LECTURA (CQRS query); no modifica nada.
 */
final class CostoProyectoService
{
    private const int SCALE = 2;

    public function calcular(Proyecto $proyecto): CostoProyecto
    {
        // Costo de arranque: lo que la obra ya llevaba gastado ANTES del
        // sistema. Suma a su propio rubro para que el desglose siga cuadrando;
        // sin esto una obra heredada arranca con margen 100% y el primer mes
        // de gastos reales parece un descontrol.
        $materiales = bcadd($this->costoMateriales($proyecto->id), $proyecto->costo_arranque_materiales, self::SCALE);
        $maquinaria = bcadd($this->costoMaquinaria($proyecto->id), $proyecto->costo_arranque_maquinaria, self::SCALE);
        $manoObra = bcadd($this->costoManoObra($proyecto->id), $proyecto->costo_arranque_mano_obra, self::SCALE);

        $costoTotal = bcadd(bcadd($materiales, $maquinaria, self::SCALE), $manoObra, self::SCALE);

        $presupuesto = (string) $proyecto->subtotal_cache;
        $margen = bcsub($presupuesto, $costoTotal, self::SCALE);

        $tienePresupuesto = bccomp($presupuesto, '0', self::SCALE) > 0;

        $margenPorcentaje = $tienePresupuesto
            ? bcmul(bcdiv($margen, $presupuesto, 6), '100', self::SCALE)
            : '0.00';

        // Porcentaje del presupuesto ya consumido por el costo real. Sin
        // presupuesto y con costo, se considera sobregiro total.
        $porcentajeConsumido = $tienePresupuesto
            ? bcmul(bcdiv($costoTotal, $presupuesto, 6), '100', self::SCALE)
            : (bccomp($costoTotal, '0', self::SCALE) > 0 ? '999.99' : '0.00');

        return new CostoProyecto(
            proyectoId: $proyecto->id,
            presupuesto: $this->n($presupuesto),
            costoMateriales: $this->n($materiales),
            costoMaquinaria: $this->n($maquinaria),
            costoManoObra: $manoObra,
            costoTotal: $this->n($costoTotal),
            margen: $this->n($margen),
            margenPorcentaje: $margenPorcentaje,
            porcentajeConsumido: $porcentajeConsumido,
            costoArranque: $this->n($proyecto->costoArranqueTotal()),
        );
    }

    /**
     * Materiales = despachado desde bodega + compras directas a obra
     * − devuelto a bodega.
     *
     * Las compras con entrega directa (EntradaCompra cuyo destino es la
     * obra) imputan costo al proyecto al precio real de factura — nunca
     * pasaron por bodega, así que el despacho no las captura.
     */
    private function costoMateriales(int $proyectoId): string
    {
        $despachado = (string) MovimientoInventario::query()
            ->where('proyecto_destino_id', $proyectoId)
            ->whereIn('tipo', [
                TipoMovimientoInventario::SalidaDespacho->value,
                TipoMovimientoInventario::EntradaCompra->value,
            ])
            ->sum('valor_total');

        // Devoluciones a bodega Y anulaciones de compra restan: material
        // (o compra) que finalmente NO es costo de la obra.
        $revertido = (string) MovimientoInventario::query()
            ->where('proyecto_origen_id', $proyectoId)
            ->whereIn('tipo', [
                TipoMovimientoInventario::Devolucion->value,
                TipoMovimientoInventario::AnulacionCompra->value,
            ])
            ->sum('valor_total');

        return bcsub($despachado, $revertido, self::SCALE);
    }

    /**
     * Maquinaria = costo de partes de trabajo + combustible de la obra.
     */
    private function costoMaquinaria(int $proyectoId): string
    {
        $deLaObra = static fn (Builder $q): Builder => $q->where('proyecto_id', $proyectoId);

        $partes = (string) ParteTrabajo::query()
            ->whereHas('asignacion', $deLaObra)
            ->sum('costo_cache');

        $combustible = (string) ConsumoCombustible::query()
            ->whereHas('asignacion', $deLaObra)
            ->sum('costo_cache');

        return bcadd($partes, $combustible, self::SCALE);
    }

    /**
     * Mano de obra = suma de las líneas de planillas CERRADAS de la obra.
     * Las planillas en borrador no cuentan (aún no es un pago confirmado).
     */
    private function costoManoObra(int $proyectoId): string
    {
        $monto = (string) PlanillaLinea::query()
            ->where('proyecto_id', $proyectoId)
            ->whereHas('planilla', static fn (Builder $q): Builder => $q->where('estado', EstadoPlanilla::Cerrada->value))
            ->sum('monto_bruto');

        return bcadd($monto, '0', self::SCALE);
    }

    /**
     * Normaliza un string numérico a exactamente 2 decimales.
     */
    private function n(string $valor): string
    {
        return bcadd($valor, '0', self::SCALE);
    }
}
