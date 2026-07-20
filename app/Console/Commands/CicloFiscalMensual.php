<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TipoReporteFiscal;
use App\Models\ReporteFiscal;
use App\Services\Reportes\GenerarReporteFiscalMensualService;
use App\Services\Reportes\GenerarReportePagosMensualService;
use App\Services\Reportes\NotificadorReportesFiscales;
use App\Services\Reportes\PurgarFotosFacturasService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ciclo fiscal mensual — corre TODOS los días desde el scheduler y es
 * idempotente, así el ciclo no se pierde si el servidor estuvo caído
 * el día 1:
 *
 *  1. Si al mes ANTERIOR le falta el reporte de facturas → lo genera
 *     y avisa. Ídem con el reporte de pagos a proveedores.
 *  2. Purga las fotos de los reportes (de ambos tipos) que ya
 *     cumplieron su colchón de 7 días (solo si su PDF está sano).
 */
class CicloFiscalMensual extends Command
{
    protected $signature = 'compras:ciclo-fiscal-mensual';

    protected $description = 'Genera los reportes (facturas y pagos) del mes anterior si faltan y purga fotos ya archivadas';

    public function handle(
        GenerarReporteFiscalMensualService $generadorFacturas,
        GenerarReportePagosMensualService $generadorPagos,
        PurgarFotosFacturasService $purgador,
        NotificadorReportesFiscales $notificador,
    ): int {
        $mesAnterior = now()->subMonthNoOverflow()->startOfMonth();

        if (! $this->yaExiste(TipoReporteFiscal::Facturas, $mesAnterior->toDateString())) {
            try {
                $reporte = $generadorFacturas->generar($mesAnterior);
                $notificador->reporteGenerado($reporte);

                $this->info("✓ Reporte fiscal {$mesAnterior->format('Y-m')} generado ({$reporte->compras_count} compras, {$reporte->fotos_count} fotos).");
            } catch (Throwable $e) {
                // No tumbar el resto del ciclo por un PDF fallido: se reintenta mañana.
                $this->error("Reporte de facturas {$mesAnterior->format('Y-m')} falló: {$e->getMessage()}");
            }
        }

        if (! $this->yaExiste(TipoReporteFiscal::Pagos, $mesAnterior->toDateString())) {
            try {
                $reporte = $generadorPagos->generar($mesAnterior);
                $notificador->reporteGenerado($reporte);

                $this->info("✓ Reporte de pagos {$mesAnterior->format('Y-m')} generado ({$reporte->compras_count} abonos, {$reporte->fotos_count} comprobantes).");
            } catch (Throwable $e) {
                $this->error("Reporte de pagos {$mesAnterior->format('Y-m')} falló: {$e->getMessage()}");
            }
        }

        $purgados = $purgador->purgar();

        $this->info($purgados > 0
            ? "✓ {$purgados} reporte(s) liberaron sus fotos del servidor."
            : 'Sin fotos pendientes de purgar.');

        return self::SUCCESS;
    }

    private function yaExiste(TipoReporteFiscal $tipo, string $periodo): bool
    {
        return ReporteFiscal::query()
            ->where('tipo', $tipo)
            ->whereDate('periodo', $periodo)
            ->exists();
    }
}
