<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use App\Enums\TipoReporteFiscal;
use App\Models\Abono;
use App\Models\Compra;
use App\Models\ReporteFiscal;
use Illuminate\Support\Facades\Storage;

/**
 * Purga de fotos archivadas — el ahorro de espacio prometido: 7 días
 * después de generado el reporte mensual (DIAS_COLCHON), borra del
 * disco las fotos QUE QUEDARON DENTRO del PDF y limpia la columna del
 * registro dueño. El PDF permanece como archivo permanente.
 *
 * Aplica a ambos tipos de reporte:
 * - facturas: fotos de facturas → columna `fotos_factura` de compras.
 * - pagos:    comprobantes de transferencia → `foto_comprobante` de abonos.
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
                    'tipo'           => $reporte->tipo->value,
                    'periodo'        => $reporte->periodo->format('Y-m'),
                    'fotos_borradas' => $borradas,
                ])
                ->event('fotos_facturas_purgadas')
                ->log("Fotos del período {$reporte->periodo->format('Y-m')} ({$reporte->tipo->getLabel()}) liberadas del servidor");

            if ($borradas > 0) {
                $this->notificador->fotosPurgadas($reporte, $borradas);
            }

            $purgados++;
        }

        return $purgados;
    }

    /**
     * Borra del disco las fotos archivadas en el PDF y las quita de la
     * columna de su dueño (compras o abonos, según el tipo de reporte),
     * dejando intactas las subidas después.
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

        match ($reporte->tipo) {
            TipoReporteFiscal::Facturas => $this->limpiarFotosDeCompras($reporte, $incluidas),
            TipoReporteFiscal::Pagos    => $this->limpiarFotosDeAbonos($incluidas),
        };

        return $borradas;
    }

    /**
     * Quita las rutas archivadas de la columna de cada compra del
     * período (dejando las fotos posteriores).
     *
     * @param array<int, string> $incluidas
     */
    private function limpiarFotosDeCompras(ReporteFiscal $reporte, array $incluidas): void
    {
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
    }

    /**
     * Limpia el comprobante de los abonos cuya foto quedó archivada
     * en el PDF (una foto por abono: la ruta identifica al dueño).
     *
     * @param array<int, string> $incluidas
     */
    private function limpiarFotosDeAbonos(array $incluidas): void
    {
        Abono::query()
            ->whereIn('foto_comprobante', $incluidas)
            ->update(['foto_comprobante' => null]);
    }
}
