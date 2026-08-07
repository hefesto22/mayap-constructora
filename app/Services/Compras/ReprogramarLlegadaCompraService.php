<?php

declare(strict_types=1);

namespace App\Services\Compras;

use App\Enums\EstadoCompra;
use App\Exceptions\Compras\LlegadaNoReprogramableException;
use App\Models\Compra;
use App\Services\Requisiciones\SeguimientoLlegadaRequisicionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mueve la fecha estimada de llegada de un pedido que ya está "por recibir"
 * (decisión Mauricio 2026-08-07).
 *
 * Hasta hoy la fecha solo se podía tocar en el borrador: una vez registrado
 * el pedido quedaba congelada, y cuando el proveedor reprogramaba no había
 * forma de reflejarlo ni de avisarle a la obra. Ese era el caso peligroso:
 * el encargado seguía esperando material para el 9 que en realidad venía
 * el 13, y el ingeniero se enteraba con la cuadrilla parada.
 *
 * Mismo patrón que `TransicionarRequisicionService::reprogramar`: motivo
 * OBLIGATORIO, rastro en la bitácora con anterior → nueva, y aviso
 * inmediato a quien depende de esa fecha.
 */
final readonly class ReprogramarLlegadaCompraService
{
    public function __construct(
        private SeguimientoLlegadaRequisicionService $seguimiento,
    ) {}

    public function reprogramar(
        Compra $compra,
        Carbon $nuevaFecha,
        string $motivo,
        ?int $userId = null,
    ): void {
        if ($compra->estado !== EstadoCompra::PorRecibir) {
            throw LlegadaNoReprogramableException::estadoInvalido($compra->codigo, $compra->estado);
        }

        if (trim($motivo) === '') {
            throw LlegadaNoReprogramableException::motivoRequerido();
        }

        if ($nuevaFecha->lt(today())) {
            throw LlegadaNoReprogramableException::fechaEnPasado($nuevaFecha->format('d/m/Y'));
        }

        DB::transaction(function () use ($compra, $nuevaFecha, $motivo, $userId): void {
            // Sin fecha previa (pedido de taller registrado sin estimado) la
            // "anterior" es la de hoy: el mensaje sigue siendo legible.
            $anterior = $compra->fecha_estimada_llegada ?? today();

            $compra->fecha_estimada_llegada = $nuevaFecha;
            // La fecha cambió → la campanita del día de llegada se rearma.
            $compra->aviso_llegada_at = null;
            $compra->save();

            // Espeja la fecha en la requisición que espera, deja el renglón
            // en su bitácora y avisa a la obra (no-op si no viene de una
            // requisición o no es entrega directa a obra).
            $this->seguimiento->reprogramar($compra, $anterior, $motivo, $userId);
        });
    }
}
