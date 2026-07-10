<?php

declare(strict_types=1);

namespace App\Services\Compras;

use App\Enums\EstadoCompra;
use App\Exceptions\Compras\CompraNoConfirmableException;
use App\Models\Compra;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Registra la compra y la manda a verificación (Borrador → Por recibir).
 *
 * NO mueve stock ni crea CxP — eso pasa hasta que el punto de llegada
 * VERIFICA lo recibido (G2). Aquí solo se congelan los totales de captura
 * y suena la campanita con el reporte de lo esperado: al bodeguero su
 * porción, al encargado de obra la suya.
 */
final readonly class MarcarPorRecibirService
{
    public function __construct(
        private ConfirmarCompraService $compras,
        private NotificadorCompras $notificador,
        private ValidarDestinoObraCompraService $destinos,
    ) {}

    public function registrar(Compra $compra, ?int $userId = null): void
    {
        if ($compra->estado !== EstadoCompra::Borrador) {
            throw CompraNoConfirmableException::estadoInvalido($compra->codigo, $compra->estado);
        }

        $compra->loadMissing('lineas');

        if ($compra->lineas->isEmpty()) {
            throw CompraNoConfirmableException::sinLineas($compra->codigo);
        }

        // Destinos a obra: obra viva + material presupuestado (o permiso
        // de comprar fuera de presupuesto). Fail fast, antes de avisar.
        $this->destinos->validar($compra, $userId !== null ? User::find($userId) : null);

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
            $this->notificador->porRecibir($compra, $userId);
        });
    }
}
