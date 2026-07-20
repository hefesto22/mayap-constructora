<?php

declare(strict_types=1);

namespace App\Services\Compras;

use App\Models\BitacoraMantenimiento;
use App\Models\Compra;

/**
 * Puente compra de repuestos ↔ mantenimiento de máquina (decisión
 * Mauricio 2026-07-20): cuando una compra de taller viene amarrada a
 * una reparación (`mantenimiento_id`):
 *
 *  - Al REGISTRAR el pedido, la fecha estimada de llegada de la compra
 *    alimenta la fecha estimada de repuestos del mantenimiento (y
 *    rearma su campanita), dejando constancia en la bitácora.
 *  - Al RECIBIR la compra, la llegada queda anotada en la bitácora.
 *
 * Best-effort sobre mantenimientos EN PROCESO; si la reparación ya se
 * finalizó, no se toca (la bitácora de un finalizado es historia).
 */
final class SincronizarRepuestosMantenimientoService
{
    public function pedidoRegistrado(Compra $compra, ?int $userId = null): void
    {
        $mantenimiento = $compra->mantenimiento;

        if ($mantenimiento === null || ! $mantenimiento->estado->esEnProceso()) {
            return;
        }

        $estimada = $compra->fecha_estimada_llegada;

        if ($estimada !== null) {
            $cambio = $mantenimiento->fecha_estimada_repuestos === null
                || ! $mantenimiento->fecha_estimada_repuestos->isSameDay($estimada);

            $mantenimiento->forceFill([
                'fecha_estimada_repuestos' => $estimada->toDateString(),
                // Nueva fecha → rearmar el aviso de llegada de repuestos.
                'aviso_repuestos_at' => $cambio ? null : $mantenimiento->aviso_repuestos_at,
            ])->save();
        }

        BitacoraMantenimiento::create([
            'mantenimiento_maquina_id' => $mantenimiento->id,
            'fase'                     => $mantenimiento->fase,
            'detalle'                  => "Pedido de repuestos {$compra->codigo} registrado"
                .($estimada !== null ? ' — llegada estimada: '.$estimada->format('d/m/Y') : '').'.',
            'user_id' => $userId,
        ]);
    }

    public function llegadaRegistrada(Compra $compra, ?int $userId = null): void
    {
        $mantenimiento = $compra->mantenimiento;

        if ($mantenimiento === null || ! $mantenimiento->estado->esEnProceso()) {
            return;
        }

        BitacoraMantenimiento::create([
            'mantenimiento_maquina_id' => $mantenimiento->id,
            'fase'                     => $mantenimiento->fase,
            'detalle'                  => "Repuestos de la compra {$compra->codigo} recibidos.",
            'user_id'                  => $userId,
        ]);
    }
}
