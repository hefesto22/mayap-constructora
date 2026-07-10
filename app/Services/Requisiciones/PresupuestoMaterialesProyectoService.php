<?php

declare(strict_types=1);

namespace App\Services\Requisiciones;

use App\Enums\CategoriaItem;
use App\Enums\EstadoCompra;
use App\Enums\EstadoRequisicion;
use App\Enums\TipoLineaFicha;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Control presupuestario de materiales de una obra (CQRS query, solo lectura).
 *
 * Responde: ¿cuánto de cada material contemplan las fichas del proyecto,
 * cuánto ya se comprometió en requisiciones y cuánto queda disponible?
 *
 *  PRESUPUESTADO — derivado de la composición aprobada del proyecto:
 *      Σ (proyecto_renglones.cantidad × ficha_lineas.rendimiento)
 *      para líneas tipo=item cuyo item referencia un material físico.
 *      El rendimiento ya es EFECTIVO (incluye desperdicio, ver FichaLinea).
 *
 *  SOLICITADO — el compromiso real contra el presupuesto:
 *      Σ COALESCE(cantidad_autorizada, cantidad_solicitada) de líneas de
 *      requisiciones NO rechazadas del proyecto. Se usa lo solicitado (no lo
 *      despachado) porque el material comprometido en requisiciones
 *      pendientes ya no está "disponible" — esperar al despacho abriría una
 *      ventana sin trazabilidad donde se puede sobre-pedir sin control.
 *      MÁS las compras confirmadas con entrega directa a la obra SIN
 *      requisición: ese material también llegó y consume presupuesto (las
 *      compras CON requisición ya cuentan vía sus líneas — no se duplican).
 *
 *  DESPACHADO — informativo para el reporte: Σ cantidad_despachada.
 *
 * SOLO CATEGORÍA MATERIALES: la herramienta y equipo (retroexcavadora,
 * concretera, medida en horas/días) NO se requisa de bodega — se gestiona
 * en el módulo de Maquinaria (asignaciones + partes de trabajo). Incluirla
 * aquí confundía al usuario ("Quedan: 1224 DIA de retroexcavadora").
 *
 * DECISIÓN DE NEGOCIO (confirmada 2026-07-05): el exceso NO se bloquea —
 * en obra real siempre hay sobreconsumo. Se permite pedir de más y queda
 * visible en el reporte de control del proyecto.
 */
final class PresupuestoMaterialesProyectoService
{
    /**
     * Estado presupuestario de todos los materiales del proyecto:
     * los presupuestados por sus fichas Y los pedidos fuera de presupuesto.
     *
     * Dos queries agregadas (no por-material) + un merge en memoria.
     *
     * @return Collection<int, PresupuestoMaterial> keyed por material_id
     */
    public function porProyecto(int $proyectoId): Collection
    {
        $presupuestado = $this->presupuestadoPorMaterial($proyectoId);
        $pedido = $this->pedidoPorMaterial($proyectoId);
        $compradoDirecto = $this->compradoDirectoSinRequisicion($proyectoId);

        $materialIds = $presupuestado->keys()
            ->merge($pedido->keys())
            ->merge($compradoDirecto->keys())
            ->unique()
            ->values();

        if ($materialIds->isEmpty()) {
            return collect();
        }

        $materiales = DB::table('materiales')
            ->join('unidades_medida', 'unidades_medida.id', '=', 'materiales.unidad_medida_id')
            ->whereIn('materiales.id', $materialIds)
            ->get([
                'materiales.id',
                'materiales.codigo',
                'materiales.nombre',
                'unidades_medida.codigo as unidad',
            ])
            ->keyBy('id');

        return $materialIds
            ->mapWithKeys(function (int $materialId) use ($presupuestado, $pedido, $compradoDirecto, $materiales): array {
                $info = $materiales->get($materialId);
                $pedidoMaterial = $pedido->get($materialId);

                // Lo comprado directo a obra sin requisición cuenta como
                // comprometido Y entregado (llegó con la factura).
                $directo = $this->d($compradoDirecto->get($materialId));

                return [$materialId => new PresupuestoMaterial(
                    materialId: $materialId,
                    materialCodigo: (string) ($info->codigo ?? '—'),
                    materialNombre: (string) ($info->nombre ?? 'MATERIAL DESCONOCIDO'),
                    unidad: (string) ($info->unidad ?? ''),
                    presupuestado: $this->d($presupuestado->get($materialId)),
                    solicitado: bcadd(
                        $this->d($pedidoMaterial !== null ? $pedidoMaterial->solicitado : null),
                        $directo,
                        4,
                    ),
                    despachado: bcadd(
                        $this->d($pedidoMaterial !== null ? $pedidoMaterial->despachado : null),
                        $directo,
                        4,
                    ),
                )];
            });
    }

    /**
     * Estado presupuestario de UN material (para el helper en vivo del
     * formulario de requisición). Retorna null si el material no está
     * presupuestado NI ha sido pedido en este proyecto.
     */
    public function paraMaterial(int $proyectoId, int $materialId): ?PresupuestoMaterial
    {
        return $this->porProyecto($proyectoId)->get($materialId);
    }

    // ─── Queries agregadas ─────────────────────────────────────────

    /**
     * @return Collection<int, string> material_id => cantidad presupuestada
     */
    private function presupuestadoPorMaterial(int $proyectoId): Collection
    {
        return DB::table('proyecto_renglones')
            ->join('ficha_lineas', 'ficha_lineas.ficha_id', '=', 'proyecto_renglones.ficha_id')
            ->join('items', 'items.id', '=', 'ficha_lineas.item_id')
            ->where('proyecto_renglones.proyecto_id', $proyectoId)
            ->where('ficha_lineas.tipo', TipoLineaFicha::Item->value)
            ->where('items.categoria', CategoriaItem::Materiales->value)
            ->whereNotNull('items.material_id')
            ->groupBy('items.material_id')
            ->selectRaw(
                'items.material_id, SUM(proyecto_renglones.cantidad * ficha_lineas.rendimiento) AS presupuestado'
            )
            ->pluck('presupuestado', 'material_id');
    }

    /**
     * Agregado crudo por material: cada fila es un stdClass con
     * `solicitado` y `despachado` (strings decimales de Postgres).
     *
     * @return Collection<int|string, stdClass>
     */
    private function pedidoPorMaterial(int $proyectoId): Collection
    {
        return DB::table('requisicion_lineas')
            ->join('requisiciones', 'requisiciones.id', '=', 'requisicion_lineas.requisicion_id')
            ->join('materiales', 'materiales.id', '=', 'requisicion_lineas.material_id')
            ->where('requisiciones.proyecto_id', $proyectoId)
            ->where('requisiciones.estado', '!=', EstadoRequisicion::Rechazada->value)
            ->where('materiales.categoria', CategoriaItem::Materiales->value)
            ->whereNull('requisiciones.deleted_at')
            ->groupBy('requisicion_lineas.material_id')
            ->selectRaw(<<<'SQL'
                requisicion_lineas.material_id,
                SUM(COALESCE(requisicion_lineas.cantidad_autorizada, requisicion_lineas.cantidad_solicitada)) AS solicitado,
                SUM(requisicion_lineas.cantidad_despachada) AS despachado
                SQL)
            ->get()
            ->keyBy('material_id');
    }

    /**
     * Compras CONFIRMADAS con entrega directa a la obra y SIN requisición:
     * material que llegó a la obra fuera del flujo de requisiciones y que
     * también consume presupuesto. Cuenta la línea si su destino propio es
     * la obra, o si hereda una cabecera cuyo destino es la obra.
     *
     * Las compras CON requisición se excluyen: sus cantidades ya cuentan
     * vía las líneas de la requisición (evita doble conteo).
     *
     * @return Collection<int|string, string> material_id => cantidad
     */
    private function compradoDirectoSinRequisicion(int $proyectoId): Collection
    {
        return DB::table('compra_lineas')
            ->join('compras', 'compras.id', '=', 'compra_lineas.compra_id')
            ->join('materiales', 'materiales.id', '=', 'compra_lineas.material_id')
            ->where('compras.estado', EstadoCompra::Confirmada->value)
            ->whereNull('compras.requisicion_id')
            ->whereNull('compras.deleted_at')
            ->where('materiales.categoria', CategoriaItem::Materiales->value)
            ->where(function ($query) use ($proyectoId): void {
                $query
                    // Destino propio de la línea = esta obra…
                    ->where('compra_lineas.proyecto_id', $proyectoId)
                    // …o hereda la cabecera y la cabecera es esta obra.
                    ->orWhere(function ($subquery) use ($proyectoId): void {
                        $subquery
                            ->whereNull('compra_lineas.proyecto_id')
                            ->whereNull('compra_lineas.bodega_id')
                            ->where('compras.proyecto_id', $proyectoId);
                    });
            })
            ->groupBy('compra_lineas.material_id')
            ->selectRaw('compra_lineas.material_id, SUM(compra_lineas.cantidad) AS total')
            ->pluck('total', 'material_id');
    }

    /**
     * Normaliza un agregado SQL (posible null) a string decimal escala 4.
     */
    private function d(int|float|string|null $valor): string
    {
        return bcadd((string) ($valor ?? '0'), '0', 4);
    }
}
