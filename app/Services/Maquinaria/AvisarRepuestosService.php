<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Enums\EstadoMantenimiento;
use App\Enums\FaseMantenimiento;
use App\Models\MantenimientoMaquina;

/**
 * Campanita de llegada de repuestos (decisión Mauricio 2026-07-20):
 * cuando un mantenimiento en proceso está esperando repuestos (fases
 * "sin repuestos" o "compra de repuestos") y su fecha estimada de
 * recepción llegó (o ya pasó), avisa UNA vez a gerencia, maquinaria y
 * recepción: "los repuestos deberían estar llegando — confirmar y
 * continuar la reparación".
 *
 * Idempotente: `aviso_repuestos_at` marca el aviso ya enviado; cambiar
 * la fecha estimada (RegistrarAvanceMantenimientoService) la reinicia
 * y rearma la campanita para la nueva fecha.
 */
final readonly class AvisarRepuestosService
{
    public function __construct(private NotificadorMantenimiento $notificador) {}

    /**
     * @return int Cuántos avisos se enviaron en esta pasada.
     */
    public function avisar(): int
    {
        $pendientes = MantenimientoMaquina::query()
            ->with('maquina:id,codigo,nombre')
            ->where('estado', EstadoMantenimiento::EnProceso)
            ->whereIn('fase', [FaseMantenimiento::SinRepuestos, FaseMantenimiento::CompraRepuestos])
            ->whereNotNull('fecha_estimada_repuestos')
            ->whereDate('fecha_estimada_repuestos', '<=', today())
            ->whereNull('aviso_repuestos_at')
            ->get();

        foreach ($pendientes as $mantenimiento) {
            $this->notificador->repuestosDeberianLlegar($mantenimiento);

            $mantenimiento->forceFill(['aviso_repuestos_at' => now()])->save();
        }

        return $pendientes->count();
    }
}
