<?php

declare(strict_types=1);

namespace App\Services\Compras;

use App\Enums\EstadoCompra;
use App\Models\Compra;
use App\Models\Requisicion;
use App\Services\Requisiciones\NotificadorRequisiciones;

/**
 * Campanita de pedidos por llegar (decisión Mauricio 2026-07-20): las
 * compras "por recibir" con fecha estimada de llegada alcanzada (hoy o
 * ya pasada) avisan UNA vez a recepción y gerencia: "el pedido debería
 * estar llegando — al recibirlo, marcarlo recibido/verificarlo".
 *
 * Idempotente: `aviso_llegada_at` marca el aviso enviado; cambiar la
 * fecha estimada en el borrador la reinicia. Aplica a cualquier
 * categoría con fecha estimada, aunque el caso típico es el pedido
 * de repuestos del taller.
 *
 * AMPLIACIÓN 2026-08-07 — el lado de la OBRA:
 *
 * Cuando el pedido es una entrega DIRECTA a la obra de una requisición,
 * el que tiene que estar pendiente no es la oficina sino el encargado que
 * pidió el material. Por eso se agregan dos avisos con su propia marca de
 * idempotencia (`requisiciones.aviso_llegada_obra_at`, separada de la de
 * la oficina para que un aviso no se coma al otro):
 *
 *  - "hoy llega material a tu obra" el día de la entrega prometida; y
 *  - seguimiento DIARIO mientras la fecha ya pasó y el material no
 *    aparece — porque ahí hay una obra parada esperando y un proveedor
 *    a quien reclamarle. Este escalón es SOLO para entregas directas a
 *    obra: los pedidos de taller siguen avisando una sola vez, como se
 *    decidió el 2026-07-20.
 */
final readonly class AvisarLlegadasComprasService
{
    public function __construct(
        private NotificadorCompras $notificador,
        private NotificadorRequisiciones $notificadorRequisiciones,
    ) {}

    /**
     * @return int Cuántos avisos se enviaron en esta pasada.
     */
    public function avisar(): int
    {
        return $this->avisarALaOficina() + $this->avisarALaObra();
    }

    /**
     * Recepción y gerencia: "el pedido debería estar llegando". Una vez.
     */
    private function avisarALaOficina(): int
    {
        $pendientes = Compra::query()
            ->with('proveedor:id,nombre')
            ->where('estado', EstadoCompra::PorRecibir)
            ->whereNotNull('fecha_estimada_llegada')
            ->whereDate('fecha_estimada_llegada', '<=', today())
            ->whereNull('aviso_llegada_at')
            ->get();

        foreach ($pendientes as $compra) {
            $this->notificador->llegadaEstimada($compra);

            $compra->forceFill(['aviso_llegada_at' => now()])->save();
        }

        return $pendientes->count();
    }

    /**
     * El encargado de la obra que pidió el material: "hoy llega" el día
     * prometido, y después un recordatorio diario hasta que aparezca.
     */
    private function avisarALaObra(): int
    {
        $enCamino = Compra::query()
            ->with(['proveedor:id,nombre', 'requisicion.proyecto.encargados'])
            ->where('estado', EstadoCompra::PorRecibir)
            ->whereNotNull('proyecto_id')
            ->whereNotNull('requisicion_id')
            ->whereNotNull('fecha_estimada_llegada')
            ->whereDate('fecha_estimada_llegada', '<=', today())
            ->get();

        $enviados = 0;

        foreach ($enCamino as $compra) {
            $requisicion = $compra->requisicion;

            // La requisición pudo borrarse (soft delete) después de la compra.
            if (! $requisicion instanceof Requisicion) {
                continue;
            }

            // Ya se avisó hoy: ni "hoy llega" ni el reclamo se repiten
            // dentro del mismo día.
            if ($requisicion->aviso_llegada_obra_at?->isToday() === true) {
                continue;
            }

            $primerAviso = $requisicion->aviso_llegada_obra_at === null;

            // Primer aviso = "hoy llega". Los siguientes solo tienen
            // sentido si la fecha YA pasó: es el reclamo al proveedor.
            if ($primerAviso) {
                $this->notificadorRequisiciones->llegaHoy($requisicion, $compra);
            } elseif ($compra->fecha_estimada_llegada?->lt(today()) === true) {
                $this->notificadorRequisiciones->llegadaVencida($requisicion, $compra);
            } else {
                continue;
            }

            $requisicion->forceFill(['aviso_llegada_obra_at' => now()])->save();
            $enviados++;
        }

        return $enviados;
    }
}
