<?php

declare(strict_types=1);

namespace App\Services\Compras;

use App\Enums\EstadoCompra;
use App\Exceptions\Compras\CompraNoCompletableException;
use App\Models\Compra;
use App\Models\User;
use App\Support\Permisos;
use Illuminate\Support\Facades\DB;

/**
 * Cierre definitivo de una compra (Completada): la conciliación final.
 *
 * Solo procede cuando TODO cuadró (facturado = recibido en cada línea) y la
 * ventana de corrección ya venció — nadie reclamó el conteo. Completada, la
 * compra queda SELLADA: sin corregir, sin anular, sin editar. El acta sigue
 * consultable como respaldo del expediente.
 *
 * Con diferencias jamás se completa: la brecha con el proveedor tiene que
 * resolverse primero (recontar si fue error propio; anular/reclamar si el
 * error vino de la factura o del envío).
 */
final readonly class CompletarCompraService
{
    /**
     * ¿Este usuario puede completar ESTA compra ya? (para el botón).
     */
    public function puedeCompletar(User $user, Compra $compra): bool
    {
        return $user->can(Permisos::COMPLETAR_COMPRA)
            && $compra->listaParaCompletar();
    }

    public function completar(Compra $compra, User $usuario): void
    {
        if (! $usuario->can(Permisos::COMPLETAR_COMPRA)) {
            throw CompraNoCompletableException::sinPermiso($compra->codigo);
        }

        if ($compra->estado !== EstadoCompra::Confirmada) {
            throw CompraNoCompletableException::estadoInvalido($compra->codigo, $compra->estado);
        }

        if (! $compra->cuadrada()) {
            throw CompraNoCompletableException::conDiferencias($compra->codigo);
        }

        if ($compra->enVentanaDeCorreccion()) {
            throw CompraNoCompletableException::ventanaAbierta(
                $compra->codigo,
                (int) config('compras.ventana_correccion_horas', 24),
            );
        }

        DB::transaction(function () use ($compra, $usuario): void {
            $bloqueada = Compra::query()
                ->whereKey($compra->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($bloqueada->estado !== EstadoCompra::Confirmada) {
                throw CompraNoCompletableException::estadoInvalido($compra->codigo, $bloqueada->estado);
            }

            $compra->update([
                'estado'         => EstadoCompra::Completada,
                'completada_at'  => now(),
                'completada_por' => $usuario->id,
            ]);
        });
    }
}
