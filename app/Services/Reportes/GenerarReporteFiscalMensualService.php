<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use App\Enums\EstadoCompra;
use App\Models\Compra;
use App\Models\ReporteFiscal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use RuntimeException;

/**
 * Reporte fiscal mensual (decisión Mauricio 2026-07-19): UN PDF por mes
 * con TODAS las compras del período — datos fiscales (documento, número,
 * ISV, totales), anuladas marcadas — y las fotos de sus facturas
 * incrustadas. El archivo de control permanente que permite purgar las
 * fotos del servidor una semana después.
 *
 * Las fotos entran al HTML como data-URI base64 (WebP): Chromium las
 * incrusta comprimidas en el PDF sin depender de rutas del disco.
 *
 * Regenerable: correr dos veces el mismo período reemplaza el PDF y
 * actualiza la MISMA fila (updateOrCreate por periodo).
 */
final class GenerarReporteFiscalMensualService
{
    public function __construct(private readonly RenderizadorPdf $pdf) {}

    /**
     * Genera (o regenera) el reporte del mes de la fecha dada, verifica
     * que el PDF quedó sano y avisa por campanita.
     */
    public function generar(CarbonInterface $mes): ReporteFiscal
    {
        $periodo = $mes->toImmutable()->startOfMonth();
        $compras = $this->comprasDelPeriodo($periodo);

        $fotos = $compras
            ->flatMap(fn (Compra $c): array => $c->fotos_factura ?? [])
            ->values();

        $rutaRelativa = 'reportes-fiscales/facturas-'.$periodo->format('Y-m').'.pdf';
        $rutaAbsoluta = Storage::disk('local')->path($rutaRelativa);

        $this->pdf->guardar($this->construirHtml($periodo, $compras), $rutaAbsoluta);

        if (! is_file($rutaAbsoluta) || filesize($rutaAbsoluta) === 0) {
            throw new RuntimeException(
                "El PDF del reporte fiscal {$periodo->format('Y-m')} no se generó correctamente."
            );
        }

        $reporte = ReporteFiscal::query()->updateOrCreate(
            ['periodo' => $periodo->toDateString()],
            [
                'path'            => $rutaRelativa,
                'compras_count'   => $compras->count(),
                'fotos_count'     => $fotos->count(),
                'fotos_incluidas' => $fotos->isEmpty() ? null : $fotos->all(),
            ],
        );

        activity('compras')
            ->performedOn($reporte)
            ->withProperties([
                'periodo' => $periodo->format('Y-m'),
                'compras' => $compras->count(),
                'fotos'   => $fotos->count(),
            ])
            ->event('reporte_fiscal_generado')
            ->log("Reporte fiscal {$periodo->format('Y-m')} generado");

        return $reporte;
    }

    /**
     * HTML del reporte — separado del PDF para ser testeable sin Chrome.
     *
     * @param Collection<int, Compra> $compras
     */
    public function construirHtml(CarbonInterface $periodo, Collection $compras): string
    {
        $activas = $compras->filter(fn (Compra $c): bool => $c->estado !== EstadoCompra::Anulada);

        return View::make('pdf.reporte-fiscal-mensual', [
            'periodo'  => $periodo,
            'compras'  => $compras,
            'totalMes' => (string) $activas->reduce(
                fn (string $suma, Compra $c): string => bcadd($suma, (string) $c->total_cache, 2),
                '0.00',
            ),
            'isvMes' => (string) $activas->reduce(
                fn (string $suma, Compra $c): string => bcadd($suma, (string) $c->isv_cache, 2),
                '0.00',
            ),
            'fotosDataUris' => $this->fotosComoDataUris($compras),
        ])->render();
    }

    /**
     * @return Collection<int, Compra>
     */
    public function comprasDelPeriodo(CarbonInterface $periodo): Collection
    {
        return Compra::query()
            ->with('proveedor:id,codigo,nombre')
            ->whereBetween('fecha', [
                $periodo->toImmutable()->startOfMonth()->toDateString(),
                $periodo->toImmutable()->endOfMonth()->toDateString(),
            ])
            ->orderBy('codigo')
            ->get();
    }

    /**
     * Rutas → data-URI base64 por compra. Fotos desaparecidas del disco
     * se omiten en silencio (mejor un hueco que un reporte caído).
     *
     * @param Collection<int, Compra> $compras
     *
     * @return array<int, array<int, string>> compra_id => data-uris
     */
    private function fotosComoDataUris(Collection $compras): array
    {
        $porCompra = [];

        foreach ($compras as $compra) {
            foreach ($compra->fotos_factura ?? [] as $ruta) {
                if (! Storage::disk('public')->exists($ruta)) {
                    continue;
                }

                $extension = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));
                $mime = match ($extension) {
                    'png'         => 'image/png',
                    'jpg', 'jpeg' => 'image/jpeg',
                    'gif'         => 'image/gif',
                    'svg'         => 'image/svg+xml',
                    default       => 'image/webp',
                };

                $porCompra[$compra->id][] = 'data:'.$mime.';base64,'
                    .base64_encode(Storage::disk('public')->get($ruta));
            }
        }

        return $porCompra;
    }
}
