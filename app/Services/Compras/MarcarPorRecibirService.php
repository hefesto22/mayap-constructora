<?php

declare(strict_types=1);

namespace App\Services\Compras;

use App\Enums\EstadoCompra;
use App\Exceptions\Compras\CompraNoConfirmableException;
use App\Models\Compra;
use App\Models\User;
use App\Services\Requisiciones\SeguimientoLlegadaRequisicionService;
use Illuminate\Support\Facades\DB;

/**
 * Registra la compra y la manda a verificación (Borrador → Por recibir).
 *
 * NO mueve stock ni crea CxP — eso pasa hasta que el punto de llegada
 * VERIFICA lo recibido (G2). Aquí solo se congelan los totales de captura
 * y suena la campanita con el reporte de lo esperado: al bodeguero su
 * porción, al encargado de obra la suya.
 *
 * Compras LIBRES (sin materiales en el conjunto, 2026-07-20): registrar
 * = "el pedido quedó hecho, en espera de llegada". No hay conteo por
 * destinos ni presupuesto de obra que validar; avisa a la oficina. La
 * amarrada a un mantenimiento sincroniza su fecha de repuestos sea cual
 * sea el conjunto (la factura mixta de taller también avisa al taller).
 */
final readonly class MarcarPorRecibirService
{
    public function __construct(
        private ConfirmarCompraService $compras,
        private NotificadorCompras $notificador,
        private ValidarDestinoObraCompraService $destinos,
        private SincronizarRepuestosMantenimientoService $repuestos,
        private SeguimientoLlegadaRequisicionService $seguimiento,
    ) {}

    public function registrar(Compra $compra, ?int $userId = null): void
    {
        if ($compra->estado !== EstadoCompra::Borrador) {
            throw CompraNoConfirmableException::estadoInvalido($compra->codigo, $compra->estado);
        }

        $compra->loadMissing('lineas');

        // CAPTURA DIFERIDA (Mauricio 2026-09-05): la compra que va a
        // bodega se registra SIN detalle — total de la factura y foto. Las
        // líneas las escribe el bodeguero al recibir, que es quien tiene
        // la mercadería enfrente. A cambio se exigen las dos cosas que
        // hacen auditable ese vacío: el total contra el que se cuadrará y
        // el respaldo de lo que se pidió.
        if ($compra->lineas->isEmpty()) {
            if (! $compra->capturaDiferida()) {
                throw CompraNoConfirmableException::sinLineas($compra->codigo);
            }

            if ($compra->total_factura === null || bccomp((string) $compra->total_factura, '0', 2) <= 0) {
                throw CompraNoConfirmableException::sinTotalDeFactura($compra->codigo);
            }

            if ($compra->fotos_factura === null || $compra->fotos_factura === []) {
                throw CompraNoConfirmableException::sinFotoDeFactura($compra->codigo);
            }

            // Y el documento fiscal, que normalmente se pide al confirmar:
            // esta compra se CONFIRMA SOLA cuando quien recibe anote lo que
            // llegó. Si falta, tronaría días después con el bodeguero
            // parado frente a la mercadería y sin poder arreglarlo. Se
            // exige acá, que es cuando alguien todavía tiene la factura en
            // la mano.
            if ($compra->tipo_documento_fiscal === null) {
                throw CompraNoConfirmableException::sinDocumentoFiscal($compra->codigo);
            }
        }

        // Entrega directa a obra que nace de una requisición: la fecha
        // prometida por el proveedor es OBLIGATORIA. Es lo único que le
        // permite a la obra saber cuándo estar pendiente — sin ella el
        // encargado se entera el día que aparece el camión, o peor, no se
        // entera del atraso (decisión Mauricio 2026-08-07).
        if ($compra->esDirectaAObra()
            && $compra->requisicion_id !== null
            && $compra->fecha_estimada_llegada === null
        ) {
            $compra->loadMissing('requisicion');

            throw CompraNoConfirmableException::sinFechaDeLlegadaParaObra(
                $compra->codigo,
                $compra->requisicion->codigo,
            );
        }

        // Destinos a obra: obra viva + material presupuestado (o permiso
        // de comprar fuera de presupuesto). Fail fast, antes de avisar.
        // Las compras libres no tienen destinos de obra que validar.
        if (! $compra->esLibre() && $compra->lineas->isNotEmpty()) {
            $this->destinos->validar($compra, $userId !== null ? User::find($userId) : null);
        }

        DB::transaction(function () use ($compra, $userId): void {
            $bloqueada = Compra::query()
                ->whereKey($compra->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($bloqueada->estado !== EstadoCompra::Borrador) {
                throw CompraNoConfirmableException::estadoInvalido($compra->codigo, $bloqueada->estado);
            }

            // Totales de referencia para el listado y la verificación
            // (los oficiales se re-fijan al confirmar, tras verificar).
            $this->compras->recalcularTotales($compra);

            $compra->estado = EstadoCompra::PorRecibir;
            $compra->save();

            // Campanita DENTRO de la transacción: rollback = sin avisos.
            if ($compra->esperandoCaptura()) {
                $this->notificador->esperandoCaptura($compra, $userId);
            } elseif ($compra->esLibre()) {
                $this->notificador->pedidoLibreRegistrado($compra, $userId);
            } else {
                $this->notificador->porRecibir($compra, $userId);
            }

            // Amarrada a una reparación: sincroniza la fecha de repuestos
            // del mantenimiento (no-op sin mantenimiento_id).
            $this->repuestos->pedidoRegistrado($compra, $userId);

            // Entrega directa a obra: la requisición que espera ese
            // material se entera de la fecha prometida, y el encargado
            // recibe campanita + WhatsApp (no-op en los demás casos).
            $this->seguimiento->programar($compra, $userId);
        });
    }
}
