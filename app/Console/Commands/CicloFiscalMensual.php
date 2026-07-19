<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ReporteFiscal;
use App\Services\Reportes\GenerarReporteFiscalMensualService;
use App\Services\Reportes\NotificadorReportesFiscales;
use App\Services\Reportes\PurgarFotosFacturasService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ciclo fiscal mensual — corre TODOS los días desde el scheduler y es
 * idempotente, así el ciclo no se pierde si el servidor estuvo caído
 * el día 1:
 *
 *  1. Si el mes ANTERIOR aún no tiene reporte → lo genera y avisa.
 *  2. Purga las fotos de los reportes que ya cumplieron su colchón de
 *     7 días (solo si su PDF está sano).
 */
class CicloFiscalMensual extends Command
{
    protected $signature = 'compras:ciclo-fiscal-mensual';

    protected $description = 'Genera el reporte fiscal del mes anterior si falta y purga fotos ya archivadas';

    public function handle(
        GenerarReporteFiscalMensualService $generador,
        PurgarFotosFacturasService $purgador,
        NotificadorReportesFiscales $notificador,
    ): int {
        $mesAnterior = now()->subMonthNoOverflow()->startOfMonth();

        if (! ReporteFiscal::query()->whereDate('periodo', $mesAnterior->toDateString())->exists()) {
            try {
                $reporte = $generador->generar($mesAnterior);
                $notificador->reporteGenerado($reporte);

                $this->info("✓ Reporte fiscal {$mesAnterior->format('Y-m')} generado ({$reporte->compras_count} compras, {$reporte->fotos_count} fotos).");
            } catch (Throwable $e) {
                // No tumbar la purga por un PDF fallido: se reintenta mañana.
                $this->error("Reporte {$mesAnterior->format('Y-m')} falló: {$e->getMessage()}");
            }
        }

        $purgados = $purgador->purgar();

        $this->info($purgados > 0
            ? "✓ {$purgados} período(s) liberaron sus fotos del servidor."
            : 'Sin fotos pendientes de purgar.');

        return self::SUCCESS;
    }
}
