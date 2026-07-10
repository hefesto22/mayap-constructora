<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use App\Models\Proyecto;
use Illuminate\Support\Facades\View;

/**
 * Genera el PDF del estado de costo de una obra con Browsershot (Chromium
 * headless), a partir de la vista Blade `pdf.costo-obra`.
 *
 * El armado del HTML está separado de la generación del PDF para poder
 * probar el contenido sin depender de Chromium en el entorno de tests.
 */
final class CostoObraPdfService
{
    public function __construct(
        private readonly CostoProyectoService $costos,
        private readonly PdfRenderer $pdf,
    ) {}

    /**
     * Renderiza el HTML del reporte (sin generar el PDF). Testeable.
     */
    public function construirHtml(Proyecto $obra): string
    {
        $obra->loadMissing(['cliente', 'zona']);

        $costo = $this->costos->calcular($obra);

        return View::make('pdf.costo-obra', [
            'obra'  => $obra,
            'costo' => $costo,
        ])->render();
    }

    /**
     * Genera el PDF y lo guarda en storage. Devuelve la ruta absoluta.
     */
    public function generar(Proyecto $obra): string
    {
        return $this->pdf->guardar(
            $this->construirHtml($obra),
            storage_path("app/reportes/costo-obra/costo-{$obra->codigo}.pdf"),
        );
    }
}
