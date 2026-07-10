<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Respuesta común de los controllers de reportes: genera el PDF y lo sirve
 * INLINE (vista previa en el visor del navegador, sin forzar descarga —
 * imprimir/guardar quedan como opción del usuario desde el visor).
 *
 * El detalle técnico de un fallo de generación va al log (y Sentry); al
 * usuario solo un 503 con mensaje accionable.
 */
trait MuestraPdfInline
{
    /**
     * @param callable(): string $generar Devuelve la ruta absoluta del PDF.
     */
    private function pdfInline(callable $generar, string $nombreArchivo): BinaryFileResponse
    {
        try {
            $ruta = $generar();
        } catch (Throwable $e) {
            report($e);

            abort(503, 'No se pudo generar el PDF. El detalle quedó registrado en el log.');
        }

        return response()->file($ruta, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nombreArchivo.'"',
        ]);
    }
}
