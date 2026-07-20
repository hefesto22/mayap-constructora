<?php

declare(strict_types=1);

namespace App\Services\Compras;

use App\Enums\EstadoCompra;
use App\Models\Compra;

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
 */
final readonly class AvisarLlegadasComprasService
{
    public function __construct(private NotificadorCompras $notificador) {}

    /**
     * @return int Cuántos avisos se enviaron en esta pasada.
     */
    public function avisar(): int
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
}
