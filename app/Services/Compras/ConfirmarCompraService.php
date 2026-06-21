<?php

declare(strict_types=1);

namespace App\Services\Compras;

use App\Enums\CondicionPago;
use App\Enums\EstadoCompra;
use App\Enums\EstadoCuentaPorPagar;
use App\Exceptions\Compras\CompraNoConfirmableException;
use App\Models\Compra;
use App\Models\CuentaPorPagar;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;
use Illuminate\Support\Facades\DB;

/**
 * Confirma una compra a proveedor: recalcula totales, registra las entradas
 * de inventario (vía el motor WAC) que capitalizan el costo al promedio
 * ponderado, y marca la compra como Confirmada.
 *
 * Es la puerta que conecta Compras con Inventario: cada línea genera una
 * `entradaCompra` con `referencia = compra`, así el stock queda trazable al
 * documento que lo originó. Todo en una transacción atómica.
 *
 * El `costo_unitario` de cada línea es el costo NETO (capitaliza a inventario).
 * El ISV del documento suma al total que se debe al proveedor (CxP) pero NO
 * entra al costo de inventario.
 */
final readonly class ConfirmarCompraService
{
    private const int SCALE_INTERNO = 12;

    private const int SCALE_MONTO = 2;

    public function __construct(
        private RegistrarMovimientoService $inventario,
    ) {}

    public function confirmar(Compra $compra, ?int $userId = null): void
    {
        if ($compra->estado !== EstadoCompra::Borrador) {
            throw CompraNoConfirmableException::estadoInvalido($compra->codigo, $compra->estado);
        }

        $compra->loadMissing('lineas');

        if ($compra->lineas->isEmpty()) {
            throw CompraNoConfirmableException::sinLineas($compra->codigo);
        }

        DB::transaction(function () use ($compra, $userId): void {
            $bodega = Ubicacion::bodega($compra->bodega_id);
            $subtotal = '0';

            foreach ($compra->lineas as $linea) {
                $cantidad = (string) $linea->cantidad;
                $costo = (string) $linea->costo_unitario;

                $subtotalLinea = $this->bcround(bcmul($cantidad, $costo, self::SCALE_INTERNO), self::SCALE_MONTO);

                $linea->subtotal = $subtotalLinea;
                $linea->save();

                $subtotal = bcadd($subtotal, $subtotalLinea, self::SCALE_INTERNO);

                // Entrada de stock real con costo neto, trazable a la compra.
                $this->inventario->entradaCompra(
                    materialId: $linea->material_id,
                    destino: $bodega,
                    cantidad: $cantidad,
                    costoUnitario: $costo,
                    fecha: $compra->fecha->toDateString(),
                    userId: $userId,
                    referencia: $compra,
                );
            }

            // ISV sobre el subtotal (si aplica).
            $isv = '0';

            if ($compra->aplica_isv) {
                $factor = bcdiv((string) $compra->isv_porcentaje, '100', self::SCALE_INTERNO);
                $isv = bcmul($subtotal, $factor, self::SCALE_INTERNO);
            }

            $compra->subtotal_cache = $this->bcround($subtotal, self::SCALE_MONTO);
            $compra->isv_cache = $this->bcround($isv, self::SCALE_MONTO);
            $compra->total_cache = $this->bcround(bcadd($subtotal, $isv, self::SCALE_INTERNO), self::SCALE_MONTO);

            $compra->estado = EstadoCompra::Confirmada;
            $compra->fecha_recepcion = $compra->fecha;
            $compra->save();

            // Compra a crédito → genera la cuenta por pagar al proveedor.
            if ($compra->condicion_pago === CondicionPago::Credito) {
                $compra->loadMissing('proveedor');
                $vencimiento = $compra->fecha->copy()->addDays($compra->proveedor->dias_credito);

                CuentaPorPagar::create([
                    'compra_id'         => $compra->id,
                    'proveedor_id'      => $compra->proveedor_id,
                    'monto_original'    => $compra->total_cache,
                    'saldo'             => $compra->total_cache,
                    'fecha_emision'     => $compra->fecha->toDateString(),
                    'fecha_vencimiento' => $vencimiento->toDateString(),
                    'estado'            => EstadoCuentaPorPagar::Pendiente,
                ]);
            }
        });
    }

    /**
     * Recalcula solo los subtotales y totales de la compra (sin mover stock),
     * útil para mantener el cache mientras la compra está en borrador.
     */
    public function recalcularTotales(Compra $compra): void
    {
        $compra->loadMissing('lineas');

        $subtotal = '0';

        foreach ($compra->lineas as $linea) {
            $subtotalLinea = $this->bcround(
                bcmul((string) $linea->cantidad, (string) $linea->costo_unitario, self::SCALE_INTERNO),
                self::SCALE_MONTO,
            );

            $linea->subtotal = $subtotalLinea;
            $linea->save();

            $subtotal = bcadd($subtotal, $subtotalLinea, self::SCALE_INTERNO);
        }

        $isv = '0';

        if ($compra->aplica_isv) {
            $factor = bcdiv((string) $compra->isv_porcentaje, '100', self::SCALE_INTERNO);
            $isv = bcmul($subtotal, $factor, self::SCALE_INTERNO);
        }

        $compra->subtotal_cache = $this->bcround($subtotal, self::SCALE_MONTO);
        $compra->isv_cache = $this->bcround($isv, self::SCALE_MONTO);
        $compra->total_cache = $this->bcround(bcadd($subtotal, $isv, self::SCALE_INTERNO), self::SCALE_MONTO);
        $compra->save();
    }

    private function bcround(string $value, int $scale): string
    {
        $factor = '0.'.str_repeat('0', $scale).'5';

        if (bccomp($value, '0', self::SCALE_INTERNO) >= 0) {
            return bcadd($value, $factor, $scale);
        }

        return bcsub($value, $factor, $scale);
    }
}
