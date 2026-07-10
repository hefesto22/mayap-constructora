<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use Spatie\Browsershot\Browsershot;

/**
 * ÚNICA puerta de HTML → PDF (Browsershot/Chromium) para los reportes.
 *
 * Centraliza la configuración de entorno (rutas de Chrome/Node, sandbox)
 * que en local (Herd/macOS) se autodetecta y en el VPS viene de .env.
 * Los servicios de reporte arman el HTML; este lo convierte.
 */
final class PdfRenderer
{
    /**
     * Convierte HTML en PDF y lo guarda en la ruta dada (la crea si hace
     * falta). Devuelve la ruta absoluta del archivo generado.
     */
    public function guardar(string $html, string $rutaDestino): string
    {
        $directorio = dirname($rutaDestino);

        if (! is_dir($directorio)) {
            mkdir($directorio, 0755, true);
        }

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
            // Modo VPS: www-data no puede crear el sandbox de Chromium, y su
            // "crashpad" exige un HOME escribible para calcular su carpeta
            // (el HOME de www-data, /var/www, no lo es — Chromium aborta con
            // "--database is required" incluso con --disable-crashpad).
            // Le damos un HOME propio dentro de storage/ del proyecto.
            $home = storage_path('app/chrome-home');

            if (! is_dir($home)) {
                mkdir($home, 0755, true);
            }

            // OJO: browser.cjs de Browsershot mergea {...options.env,
            // ...process.env} — process.env GANA. Por eso el HOME se define
            // en el entorno del propio proceso PHP (putenv): node lo hereda
            // y llega a Chromium sin que nada lo pise.
            putenv('HOME='.$home);

            $shot->noSandbox()
                ->addChromiumArguments(['disable-crashpad'])
                ->setEnvironmentOptions(['HOME' => $home]);
        }

        $shot->savePdf($rutaDestino);

        return $rutaDestino;
    }
}
