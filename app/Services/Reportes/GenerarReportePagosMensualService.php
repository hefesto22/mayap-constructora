<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use App\Enums\EstadoCuentaPorPagar;
use App\Enums\TipoReporteFiscal;
use App\Models\Abono;
use App\Models\CuentaPorPagar;
use App\Models\ReporteFiscal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use RuntimeException;

/**
 * Reporte mensual de pagos a proveedores (decisión Mauricio 2026-07-20):
 * UN PDF por mes con la lógica pactada —
 *
 * - Cada mes muestra SOLO los depósitos (abonos) hechos ese mes, con
 *   las fotos de sus comprobantes de transferencia incrustadas.
 * - Una compra que se terminó de pagar aparece marcada PAGADA en el
 *   mes en que se saldó, con el registro de en qué meses se hicieron
 *   sus abonos (compra pagada el mismo mes: aparece ese mismo mes).
 *
 * Las fotos entran al HTML como data-URI base64 (WebP): Chromium las
 * incrusta comprimidas en el PDF sin depender de rutas del disco. El
 * PDF es el archivo permanente que permite purgar los comprobantes
 * del servidor una semana después (mismo colchón que las facturas).
 *
 * Regenerable: correr dos veces el mismo período reemplaza el PDF y
 * actualiza la MISMA fila (updateOrCreate por tipo + periodo).
 */
final class GenerarReportePagosMensualService
{
    public function __construct(private readonly RenderizadorPdf $pdf) {}

    /**
     * Genera (o regenera) el reporte de pagos del mes de la fecha dada
     * y verifica que el PDF quedó sano.
     */
    public function generar(CarbonInterface $mes): ReporteFiscal
    {
        $periodo = $mes->toImmutable()->startOfMonth();
        $abonos = $this->abonosDelPeriodo($periodo);
        $saldadas = $this->cuentasSaldadasEn($periodo);

        $fotos = $abonos
            ->map(fn (Abono $a): ?string => $a->foto_comprobante)
            ->filter()
            ->values();

        $rutaRelativa = 'reportes-fiscales/pagos-'.$periodo->format('Y-m').'.pdf';
        $rutaAbsoluta = Storage::disk('local')->path($rutaRelativa);

        $this->pdf->guardar($this->construirHtml($periodo, $abonos, $saldadas), $rutaAbsoluta);

        if (! is_file($rutaAbsoluta) || filesize($rutaAbsoluta) === 0) {
            throw new RuntimeException(
                "El PDF del reporte de pagos {$periodo->format('Y-m')} no se generó correctamente."
            );
        }

        $reporte = ReporteFiscal::query()->updateOrCreate(
            [
                'tipo'    => TipoReporteFiscal::Pagos,
                'periodo' => $periodo->toDateString(),
            ],
            [
                'path'            => $rutaRelativa,
                'compras_count'   => $abonos->count(),
                'fotos_count'     => $fotos->count(),
                'fotos_incluidas' => $fotos->isEmpty() ? null : $fotos->all(),
            ],
        );

        activity('compras')
            ->performedOn($reporte)
            ->withProperties([
                'periodo'  => $periodo->format('Y-m'),
                'abonos'   => $abonos->count(),
                'saldadas' => $saldadas->count(),
                'fotos'    => $fotos->count(),
            ])
            ->event('reporte_pagos_generado')
            ->log("Reporte de pagos {$periodo->format('Y-m')} generado");

        return $reporte;
    }

    /**
     * HTML del reporte — separado del PDF para ser testeable sin Chrome.
     *
     * @param Collection<int, Abono> $abonos
     * @param Collection<int, CuentaPorPagar> $saldadas
     */
    public function construirHtml(CarbonInterface $periodo, Collection $abonos, Collection $saldadas): string
    {
        return View::make('pdf.reporte-pagos-mensual', [
            'periodo'      => $periodo,
            'abonos'       => $abonos,
            'saldadas'     => $saldadas,
            'totalAbonado' => (string) $abonos->reduce(
                fn (string $suma, Abono $a): string => bcadd($suma, (string) $a->monto, 2),
                '0.00',
            ),
            'fotosDataUris' => $this->fotosComoDataUris($abonos),
            'historial'     => $this->historialDeAbonos($saldadas),
        ])->render();
    }

    /**
     * Los depósitos hechos DENTRO del mes, en orden cronológico.
     *
     * @return Collection<int, Abono>
     */
    public function abonosDelPeriodo(CarbonInterface $periodo): Collection
    {
        return Abono::query()
            ->with([
                'cuentaPorPagar.compra:id,codigo',
                'cuentaPorPagar.proveedor:id,nombre',
                'user:id,name',
            ])
            ->whereBetween('fecha', [
                $periodo->toImmutable()->startOfMonth()->toDateString(),
                $periodo->toImmutable()->endOfMonth()->toDateString(),
            ])
            ->orderBy('fecha')
            ->orderBy('id')
            ->get();
    }

    /**
     * Cuentas que se TERMINARON de pagar este mes: estado pagada, con
     * algún abono dentro del mes y NINGUNO después (su último depósito
     * cayó en este período). Aparecen PAGADAS con su historial.
     *
     * @return Collection<int, CuentaPorPagar>
     */
    public function cuentasSaldadasEn(CarbonInterface $periodo): Collection
    {
        $inicio = $periodo->toImmutable()->startOfMonth()->toDateString();
        $fin = $periodo->toImmutable()->endOfMonth()->toDateString();

        return CuentaPorPagar::query()
            ->with(['compra:id,codigo', 'proveedor:id,nombre', 'abonos'])
            ->where('estado', EstadoCuentaPorPagar::Pagada)
            ->whereHas('abonos', fn ($q) => $q->whereBetween('fecha', [$inicio, $fin]))
            ->whereDoesntHave('abonos', fn ($q) => $q->whereDate('fecha', '>', $fin))
            ->orderBy('fecha_vencimiento')
            ->get();
    }

    /**
     * Historial "en qué meses se abonó" de cada cuenta saldada:
     * cuenta_id => ["Mayo 2026 — L 5,000.00 (2 abonos)", ...].
     *
     * @param Collection<int, CuentaPorPagar> $saldadas
     *
     * @return array<int, list<string>>
     */
    private function historialDeAbonos(Collection $saldadas): array
    {
        $historial = [];

        foreach ($saldadas as $cuenta) {
            $porMes = $cuenta->abonos
                ->sortBy(fn (Abono $a): string => $a->fecha->toDateString())
                ->groupBy(fn (Abono $a): string => $a->fecha->format('Y-m'));

            $lineas = [];

            foreach ($porMes as $abonosDelMes) {
                $primero = $abonosDelMes->first();

                if ($primero === null) {
                    continue;
                }

                $total = (string) $abonosDelMes->reduce(
                    fn (string $suma, Abono $a): string => bcadd($suma, (string) $a->monto, 2),
                    '0.00',
                );

                $n = $abonosDelMes->count();

                $lineas[] = ucfirst($primero->fecha->translatedFormat('F Y'))
                    .' — L '.number_format((float) $total, 2)
                    ." ({$n} abono".($n === 1 ? '' : 's').')';
            }

            $historial[$cuenta->id] = $lineas;
        }

        return $historial;
    }

    /**
     * Rutas → data-URI base64 por abono. Fotos desaparecidas del disco
     * se omiten en silencio (mejor un hueco que un reporte caído).
     *
     * @param Collection<int, Abono> $abonos
     *
     * @return array<int, string> abono_id => data-uri
     */
    private function fotosComoDataUris(Collection $abonos): array
    {
        $porAbono = [];

        foreach ($abonos as $abono) {
            $ruta = $abono->foto_comprobante;

            if ($ruta === null || ! Storage::disk('public')->exists($ruta)) {
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

            $porAbono[$abono->id] = 'data:'.$mime.';base64,'
                .base64_encode(Storage::disk('public')->get($ruta));
        }

        return $porAbono;
    }
}
