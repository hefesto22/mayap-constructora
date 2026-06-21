<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use App\Models\Proyecto;
use Illuminate\Support\Facades\View;
use Spatie\Browsershot\Browsershot;

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
        $html = $this->construirHtml($obra);

        $directorio = storage_path('app/reportes/costo-obra');

        if (! is_dir($directorio)) {
            mkdir($directorio, 0755, true);
        }

        $ruta = "{$directorio}/costo-{$obra->codigo}.pdf";

        $shot = Browsershot::html($html)
            ->format('Letter')
            ->margins(12, 12, 12, 12)
            ->showBackground();

        $chromePath = config('browsershot.chrome_path');

        if (is_string($chromePath) && $chromePath !== '') {
            $shot->setChromePath($chromePath);
        }

        $nodeBinary = config('browsershot.node_binary');

        if (is_string($nodeBinary) && $nodeBinary !== '') {
            $shot->setNodeBinary($nodeBinary);
        }

        if (config('browsershot.no_sandbox') === true) {
            $shot->noSandbox();
        }

        $shot->savePdf($ruta);

        return $ruta;
    }
}
