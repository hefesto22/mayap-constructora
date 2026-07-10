<?php

declare(strict_types=1);

namespace App\Services\Compras;

use App\Enums\CondicionPago;
use App\Enums\EstadoCompra;
use App\Enums\EstadoCuentaPorPagar;
use App\Enums\EstadoRequisicion;
use App\Exceptions\Compras\CompraNoConfirmableException;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\CuentaPorPagar;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;
use App\Services\Requisiciones\TransicionarRequisicionService;
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
        private TransicionarRequisicionService $requisiciones,
    ) {}

    /**
     * Estados desde los que se puede confirmar: Borrador (flujo directo,
     * retrocompatible) o PorRecibir (flujo G2 — lo invoca la verificación
     * cuando todas las líneas quedaron contadas).
     *
     * @var list<EstadoCompra>
     */
    private const array ESTADOS_CONFIRMABLES = [EstadoCompra::Borrador, EstadoCompra::PorRecibir];

    public function confirmar(Compra $compra, ?int $userId = null): void
    {
        if (! in_array($compra->estado, self::ESTADOS_CONFIRMABLES, strict: true)) {
            throw CompraNoConfirmableException::estadoInvalido($compra->codigo, $compra->estado);
        }

        $compra->loadMissing('lineas');

        if ($compra->lineas->isEmpty()) {
            throw CompraNoConfirmableException::sinLineas($compra->codigo);
        }

        // Compra directa a obra enlazada a requisición: la requisición debe
        // ser de la MISMA obra — si no, el costo se imputaría al proyecto
        // equivocado.
        if ($compra->esDirectaAObra() && $compra->requisicion_id !== null) {
            $compra->loadMissing('requisicion');

            if ($compra->requisicion->proyecto_id !== $compra->proyecto_id) {
                throw CompraNoConfirmableException::requisicionDeOtraObra(
                    $compra->codigo,
                    $compra->requisicion->codigo,
                );
            }
        }

        DB::transaction(function () use ($compra, $userId): void {
            // ── Guard de concurrencia ───────────────────────────────────
            // Doble click en "Confirmar" (o dos usuarios a la vez): ambos
            // pasan la validación externa. El lock serializa; el segundo
            // espera, relee el estado ya Confirmada y explota ANTES de
            // duplicar stock y CxP.
            $bloqueada = Compra::query()
                ->whereKey($compra->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($bloqueada->estado, self::ESTADOS_CONFIRMABLES, strict: true)) {
                throw CompraNoConfirmableException::estadoInvalido($compra->codigo, $bloqueada->estado);
            }

            // ── Pasada 1: subtotales de línea y subtotal de la factura ──
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

            // ── Prorrateo de flete − descuento (landed cost, NIC 2) ─────
            // Cada línea absorbe su parte proporcional a su valor; el
            // residuo de redondeo se asigna a la última línea para que la
            // suma cuadre al céntimo con la factura.
            $ajusteGlobal = bcsub((string) $compra->costo_envio, (string) $compra->descuento, self::SCALE_INTERNO);
            $ajustes = $this->prorratear($compra, $subtotal, $ajusteGlobal);

            // ── Pasada 2: entradas de inventario al costo efectivo ──────
            $compra->lineas->loadMissing('material:id,nombre,consumo_inmediato');

            foreach ($compra->lineas as $linea) {
                $cantidadFacturada = (string) $linea->cantidad;
                $valorEfectivo = bcadd((string) $linea->subtotal, $ajustes[$linea->id] ?? '0', self::SCALE_INTERNO);

                if (bccomp($valorEfectivo, '0', self::SCALE_MONTO) < 0) {
                    throw CompraNoConfirmableException::descuentoExcedeValor($compra->codigo);
                }

                // El costo unitario efectivo sale de la FACTURA (valor con
                // flete/descuento ÷ cantidad facturada); al inventario entra
                // la cantidad RECIBIDA (G2) a ese costo. La diferencia
                // facturado−recibido es el reclamo al proveedor, no costo.
                $costoEfectivo = bcdiv($valorEfectivo, $cantidadFacturada, self::SCALE_INTERNO);
                $cantidadRecibida = $linea->cantidadEfectiva();
                $destino = $this->destinoDeLinea($compra, $linea);

                // Material de consumo inmediato (agua de pipa): no es
                // almacenable — comprarlo A BODEGA es un error de captura.
                if ($linea->material->consumo_inmediato && $destino->esBodega()) {
                    throw CompraNoConfirmableException::consumoInmediatoABodega(
                        $compra->codigo,
                        $linea->material->nombre,
                    );
                }

                // Nada llegó de esta línea: no hay stock que registrar.
                if (bccomp($cantidadRecibida, '0', 4) <= 0) {
                    continue;
                }

                // Entrada de stock real con costo efectivo (flete/descuento
                // incluidos), al destino de la LÍNEA o al de la cabecera.
                $this->inventario->entradaCompra(
                    materialId: $linea->material_id,
                    destino: $destino,
                    cantidad: $cantidadRecibida,
                    costoUnitario: $costoEfectivo,
                    fecha: $compra->fecha->toDateString(),
                    userId: $userId,
                    referencia: $compra,
                );

                // Consumo automático: el costo ya quedó imputado a la obra
                // con la entrada; esto da de baja la existencia física para
                // no acumular "stock fantasma" de un consumible.
                if ($linea->material->consumo_inmediato) {
                    $this->inventario->consumoObra(
                        materialId: $linea->material_id,
                        origen: $destino,
                        cantidad: $cantidadRecibida,
                        motivo: 'Consumo inmediato al recibir (material no almacenable).',
                        fecha: $compra->fecha->toDateString(),
                        userId: $userId,
                        referencia: $compra,
                    );
                }
            }

            // ── Totales del documento ───────────────────────────────────
            // El ISV grava SOLO el valor efectivo (flete/descuento
            // prorrateados incluidos) de las líneas NO exentas — igual que
            // la factura SAR separa Importe Exento / Gravado 15%. El ISV va
            // a la CxP, no al costo (crédito fiscal).
            $base = bcadd($subtotal, $ajusteGlobal, self::SCALE_INTERNO);
            $isv = '0';

            if ($compra->aplica_isv) {
                $factor = bcdiv((string) $compra->isv_porcentaje, '100', self::SCALE_INTERNO);
                $isv = bcmul($this->baseGravada($compra, $ajustes), $factor, self::SCALE_INTERNO);
            }

            $compra->subtotal_cache = $this->bcround($subtotal, self::SCALE_MONTO);
            $compra->isv_cache = $this->bcround($isv, self::SCALE_MONTO);
            $compra->total_cache = $this->bcround(bcadd($base, $isv, self::SCALE_INTERNO), self::SCALE_MONTO);

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

            // Requisición enlazada: las líneas que llegaron DIRECTO a la obra
            // de la requisición quedan despachadas y la requisición avanza.
            // Las líneas a bodega no despachan (se despachan después, normal).
            if ($compra->requisicion_id !== null) {
                $compra->loadMissing('requisicion');
                $obraRequisicion = $compra->requisicion->proyecto_id;

                // La requisición despacha lo que LLEGÓ, no lo facturado.
                $compradoParaLaObra = $compra->lineas
                    ->filter(fn ($linea): bool => $this->destinoDeLinea($compra, $linea)
                        ->esIgualA(Ubicacion::obra($obraRequisicion)))
                    ->groupBy('material_id')
                    ->map(fn ($lineas): string => (string) $lineas->sum(
                        fn (CompraLinea $l): float => (float) $l->cantidadEfectiva(),
                    ))
                    ->all();

                if ($compradoParaLaObra !== []
                    && $compra->requisicion->estado->puedeTransicionarA(EstadoRequisicion::Despachada)) {
                    $this->requisiciones->despacharPorCompraDirecta(
                        requisicion: $compra->requisicion,
                        compradoPorMaterial: $compradoParaLaObra,
                        codigoCompra: $compra->codigo,
                        userId: $userId,
                    );
                }
            }
        });
    }

    /**
     * Costo unitario EFECTIVO de cada línea (subtotal + flete − descuento
     * prorrateados ÷ cantidad facturada) — el mismo con el que el stock
     * entró al confirmar. Lo consume la corrección de recepciones para
     * ajustar inventario sin re-derivar la fórmula (única fuente).
     *
     * Requiere subtotales ya calculados (compra confirmada o recalculada).
     *
     * @return array<int, string> linea_id => costo unitario (escala 12)
     */
    public function costosEfectivosPorLinea(Compra $compra): array
    {
        $compra->loadMissing('lineas');

        $subtotal = '0';

        foreach ($compra->lineas as $linea) {
            $subtotal = bcadd($subtotal, (string) $linea->subtotal, self::SCALE_INTERNO);
        }

        $ajusteGlobal = bcsub((string) $compra->costo_envio, (string) $compra->descuento, self::SCALE_INTERNO);
        $ajustes = $this->prorratear($compra, $subtotal, $ajusteGlobal);

        $costos = [];

        foreach ($compra->lineas as $linea) {
            $valorEfectivo = bcadd((string) $linea->subtotal, $ajustes[$linea->id] ?? '0', self::SCALE_INTERNO);
            $costos[$linea->id] = bcdiv($valorEfectivo, (string) $linea->cantidad, self::SCALE_INTERNO);
        }

        return $costos;
    }

    /**
     * Destino efectivo de una línea — delega en la ÚNICA fuente (modelo).
     */
    private function destinoDeLinea(Compra $compra, CompraLinea $linea): Ubicacion
    {
        return $compra->destinoDeLinea($linea);
    }

    /**
     * Valor efectivo (subtotal + ajuste prorrateado) de las líneas
     * GRAVADAS — la base sobre la que se calcula el ISV.
     *
     * @param array<int, string> $ajustes linea_id => ajuste
     */
    private function baseGravada(Compra $compra, array $ajustes): string
    {
        $base = '0';

        foreach ($compra->lineas as $linea) {
            if ($linea->exento) {
                continue;
            }

            $base = bcadd(
                $base,
                bcadd((string) $linea->subtotal, $ajustes[$linea->id] ?? '0', self::SCALE_INTERNO),
                self::SCALE_INTERNO,
            );
        }

        return $base;
    }

    /**
     * Prorratea el ajuste global (flete − descuento) entre las líneas en
     * proporción a su subtotal, con el residuo de redondeo en la última
     * línea para conservar el total al céntimo.
     *
     * @return array<int, string> linea_id => ajuste (escala 2, puede ser negativo)
     */
    private function prorratear(Compra $compra, string $subtotalTotal, string $ajusteGlobal): array
    {
        if (bccomp($ajusteGlobal, '0', self::SCALE_MONTO) === 0
            || bccomp($subtotalTotal, '0', self::SCALE_MONTO) <= 0) {
            return [];
        }

        $ajustes = [];
        $acumulado = '0';
        $ultima = $compra->lineas->last();

        foreach ($compra->lineas as $linea) {
            if ($linea->is($ultima)) {
                // Residuo: lo que falte para que la suma cuadre exacto.
                $ajustes[$linea->id] = bcsub($ajusteGlobal, $acumulado, self::SCALE_MONTO);

                continue;
            }

            $proporcion = bcdiv((string) $linea->subtotal, $subtotalTotal, self::SCALE_INTERNO);
            $ajuste = $this->bcround(bcmul($ajusteGlobal, $proporcion, self::SCALE_INTERNO), self::SCALE_MONTO);

            $ajustes[$linea->id] = $ajuste;
            $acumulado = bcadd($acumulado, $ajuste, self::SCALE_INTERNO);
        }

        return $ajustes;
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

        $ajusteGlobal = bcsub((string) $compra->costo_envio, (string) $compra->descuento, self::SCALE_INTERNO);
        $base = bcadd($subtotal, $ajusteGlobal, self::SCALE_INTERNO);
        $isv = '0';

        if ($compra->aplica_isv) {
            $factor = bcdiv((string) $compra->isv_porcentaje, '100', self::SCALE_INTERNO);
            $isv = bcmul(
                $this->baseGravada($compra, $this->prorratear($compra, $subtotal, $ajusteGlobal)),
                $factor,
                self::SCALE_INTERNO,
            );
        }

        $compra->subtotal_cache = $this->bcround($subtotal, self::SCALE_MONTO);
        $compra->isv_cache = $this->bcround($isv, self::SCALE_MONTO);
        $compra->total_cache = $this->bcround(bcadd($base, $isv, self::SCALE_INTERNO), self::SCALE_MONTO);
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
