<?php

declare(strict_types=1);

namespace App\Services\Compras;

use App\Enums\EstadoCompra;
use App\Enums\EstadoRequisicion;
use App\Enums\TipoMovimientoInventario;
use App\Exceptions\Compras\CompraNoAnulableException;
use App\Exceptions\Inventario\StockInsuficienteException;
use App\Models\Compra;
use App\Models\MovimientoInventario;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;
use App\Services\Requisiciones\TransicionarRequisicionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Anula una compra CONFIRMADA revirtiendo todos sus efectos, con rastro:
 *
 *  1. Inventario: por cada entrada que la compra registró, una baja
 *     `anulacion_compra` al VALOR EXACTO de esa entrada — la valuación
 *     (WAC) queda como si la compra nunca hubiera pasado. Si la línea era
 *     de consumo inmediato (agua de pipa), primero se restaura el stock
 *     consumido (ajuste positivo) y luego se revierte — par neto cero.
 *  2. CxP: se elimina si era a crédito y NO tiene abonos.
 *  3. Requisición despachada por esta compra: regresa a RequisicionCompra
 *     con sus cantidades revertidas (hay que volver a comprar).
 *  4. La compra queda Anulada con motivo, fecha y responsable.
 *
 * REGLAS DE BLOQUEO (fail fast, nada a medias):
 *  - Solo compras Confirmadas.
 *  - Stock ya usado/despachado → no se anula (el camino es devolución a
 *    proveedor o ajuste, no reescribir la historia).
 *  - CxP con abonos → no se anula (hay dinero real pagado).
 *  - Requisición más allá de Despachada → no se anula (el material ya
 *    se movió físicamente).
 *
 * Todo en una transacción con lock: o se revierte completo o nada.
 */
final readonly class AnularCompraService
{
    private const int SCALE_INTERNO = 12;

    public function __construct(
        private RegistrarMovimientoService $inventario,
        private TransicionarRequisicionService $requisiciones,
    ) {}

    public function anular(Compra $compra, string $motivo, ?int $userId = null): void
    {
        if (trim($motivo) === '') {
            throw CompraNoAnulableException::motivoRequerido($compra->codigo);
        }

        // Confirmada: reversa completa. PorRecibir: aún no hay stock ni
        // CxP — anular es solo marcar (el material nunca se contó).
        $anulables = [EstadoCompra::Confirmada, EstadoCompra::PorRecibir];

        if (! in_array($compra->estado, $anulables, strict: true)) {
            throw CompraNoAnulableException::estadoInvalido($compra->codigo, $compra->estado);
        }

        DB::transaction(function () use ($compra, $motivo, $userId, $anulables): void {
            // Guard de concurrencia (mismo patrón que confirmar): el lock
            // serializa dobles clicks; el segundo relee y explota antes de
            // revertir dos veces.
            $bloqueada = Compra::query()
                ->whereKey($compra->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($bloqueada->estado, $anulables, strict: true)) {
                throw CompraNoAnulableException::estadoInvalido($compra->codigo, $bloqueada->estado);
            }

            $this->revertirCuentaPorPagar($compra);

            $entradas = $this->revertirInventario($compra, $motivo, $userId);

            $this->revertirRequisicion($compra, $entradas, $userId);

            $compra->estado = EstadoCompra::Anulada;
            $compra->motivo_anulacion = $motivo;
            $compra->anulada_at = now();
            $compra->anulada_por = $userId;
            $compra->save();
        });
    }

    /**
     * CxP de la compra: con abonos se bloquea la anulación; sin abonos se
     * elimina (la deuda nunca existió).
     */
    private function revertirCuentaPorPagar(Compra $compra): void
    {
        $cuenta = $compra->cuentaPorPagar()->first();

        if ($cuenta === null) {
            return;
        }

        if ($cuenta->abonos()->exists()) {
            throw CompraNoAnulableException::cuentaConAbonos($compra->codigo);
        }

        $cuenta->delete();
    }

    /**
     * Reversa del inventario, entrada por entrada, al valor exacto que
     * cada una metió. Devuelve las entradas revertidas (las usa la
     * reversa de la requisición).
     *
     * @return Collection<int, MovimientoInventario>
     */
    private function revertirInventario(Compra $compra, string $motivo, ?int $userId)
    {
        $entradas = $compra->movimientos()
            ->where('tipo', TipoMovimientoInventario::EntradaCompra->value)
            ->get();

        // Consumos inmediatos que la propia compra generó (agua de pipa),
        // indexados por material+obra para casarlos con su entrada.
        $consumos = $compra->movimientos()
            ->where('tipo', TipoMovimientoInventario::ConsumoObra->value)
            ->get()
            ->keyBy(fn (MovimientoInventario $m): string => "{$m->material_id}-{$m->proyecto_origen_id}");

        foreach ($entradas as $entrada) {
            $origen = $entrada->proyecto_destino_id !== null
                ? Ubicacion::obra($entrada->proyecto_destino_id)
                : Ubicacion::bodega((int) $entrada->bodega_destino_id);

            // Consumo inmediato: restaurar primero el stock consumido para
            // que la reversa tenga qué revertir (par neto cero, trazable).
            $consumo = $consumos->get("{$entrada->material_id}-{$entrada->proyecto_destino_id}");

            if ($consumo !== null) {
                $this->inventario->ajustePositivo(
                    materialId: $entrada->material_id,
                    destino: $origen,
                    cantidad: (string) $consumo->cantidad,
                    costoUnitario: bcdiv((string) $consumo->valor_total, (string) $consumo->cantidad, self::SCALE_INTERNO),
                    motivo: "Reversa del consumo inmediato por anulación de la compra {$compra->codigo}.",
                    userId: $userId,
                );
            }

            try {
                $this->inventario->anulacionCompra(
                    materialId: $entrada->material_id,
                    origen: $origen,
                    cantidad: (string) $entrada->cantidad,
                    valorARevertir: (string) $entrada->valor_total,
                    motivo: "Anulación de la compra {$compra->codigo}: {$motivo}",
                    userId: $userId,
                    referencia: $compra,
                );
            } catch (StockInsuficienteException $e) {
                // Rollback total: nada quedó a medias.
                throw CompraNoAnulableException::stockYaUsado($compra->codigo, $e->getMessage());
            }
        }

        return $entradas;
    }

    /**
     * Si la compra despachó una requisición por compra directa, la regresa
     * a RequisicionCompra (hay que volver a comprar) con sus cantidades
     * revertidas. Más allá de Despachada el material ya se movió → bloqueo.
     *
     * @param Collection<int, MovimientoInventario> $entradas
     */
    private function revertirRequisicion(Compra $compra, $entradas, ?int $userId): void
    {
        if ($compra->requisicion_id === null) {
            return;
        }

        $compra->loadMissing('requisicion');
        $requisicion = $compra->requisicion;

        if (in_array($requisicion->estado, [
            EstadoRequisicion::EnTransito,
            EstadoRequisicion::Recibida,
            EstadoRequisicion::Cerrada,
            EstadoRequisicion::Discrepancia,
        ], strict: true)) {
            throw CompraNoAnulableException::requisicionAvanzada($compra->codigo, $requisicion->codigo);
        }

        if ($requisicion->estado !== EstadoRequisicion::Despachada) {
            return; // Nunca la despachó esta compra: nada que revertir.
        }

        $compradoPorMaterial = $entradas
            ->filter(fn (MovimientoInventario $m): bool => $m->proyecto_destino_id === $requisicion->proyecto_id)
            ->groupBy('material_id')
            ->map(fn ($grupo): string => (string) $grupo->sum('cantidad'))
            ->all();

        if ($compradoPorMaterial === []) {
            return;
        }

        $this->requisiciones->revertirDespachoDirecto(
            requisicion: $requisicion,
            compradoPorMaterial: $compradoPorMaterial,
            codigoCompra: $compra->codigo,
            userId: $userId,
        );
    }
}
