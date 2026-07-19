<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use App\Models\Compra;
use App\Models\ReporteFiscal;
use Illuminate\Support\Facades\Storage;

/**
 * Purga de fotos de facturas — el ahorro de espacio prometido: 7 días
 * después de generado el reporte fiscal mensual (DIAS_COLCHON), borra
 * del disco las fotos QUE QUEDARON DENTRO del PDF y limpia la columna
 * de cada compra. El PDF permanece como archivo permanente.
 *
 * Salvaguardas:
 *  - JAMÁS purga si el PDF no existe o está vacío (primero regenerar).
 *  - Solo borra las rutas de `fotos_incluidas` — una foto subida
 *    DESPUÉS de generar el reporte no se toca (se archivará al
 *    regenerar o en un reporte posterior).
 *  - Idempotente: `fotos_purgadas_at` marca el mes ya liberado.
 */
final class PurgarFotosFacturasService
{
    public function __construct(private readonly NotificadorReportesFiscales $notificador) {}

    /**
     * @return int Cuántos reportes liberaron sus fotos en esta pasada.
     */
    public function purgar(): int
    {
        $pendientes = ReporteFiscal::query()
            ->whereNull('fotos_purgadas_at')
            ->where('created_at', '<=', now()->subDays(ReporteFiscal::DIAS_COLCHON))
            ->get();

        $purgados = 0;

        foreach ($pendientes as $reporte) {
            if (! $reporte->pdfSano()) {
                continue; // Sin PDF sano no se borra nada: regenerar primero.
            }

            $borradas = $this->borrarFotos($reporte);

            $reporte->forceFill(['fotos_purgadas_at' => now()])->save();

            activity('compras')
                ->performedOn($reporte)
                ->withProperties([
                    'periodo'        => $reporte->periodo->format('Y-m'),
                    'fotos_borradas' => $borradas,
                ])
                ->event('fotos_facturas_purgadas')
                ->log("Fotos del período {$reporte->periodo->format('Y-m')} liberadas del servidor");

            if ($borradas > 0) {
                $this->notificador->fotosPurgadas($reporte, $borradas);
            }

            $purgados++;
        }

        return $purgados;
    }

    /**
     * Borra del disco las fotos archivadas en el PDF y las quita de la
     * columna de cada compra del período (dejando las posteriores).
     */
    private function borrarFotos(ReporteFiscal $reporte): int
    {
        $incluidas = $reporte->fotos_incluidas ?? [];

        if ($incluidas === []) {
            return 0;
        }

        $borradas = 0;

        foreach ($incluidas as $ruta) {
            if (Storage::disk('public')->exists($ruta) && Storage::disk('public')->delete($ruta)) {
                $borradas++;
            }
        }

        $periodo = $reporte->periodo->toImmutable();

        Compra::query()
            ->whereBetween('fecha', [
                $periodo->startOfMonth()->toDateString(),
                $periodo->endOfMonth()->toDateString(),
            ])
            ->whereNotNull('fotos_factura')
            ->get()
            ->each(function (Compra $compra) use ($incluidas): void {
                $restantes = array_values(array_diff($compra->fotos_factura ?? [], $incluidas));

                $compra->forceFill([
                    'fotos_factura' => $restantes === [] ? null : $restantes,
                ])->save();
            });

        return $borradas;
    }
}
