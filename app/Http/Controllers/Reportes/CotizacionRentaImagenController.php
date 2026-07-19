<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Models\Proyecto;
use App\Services\Reportes\CotizacionRentaService;
use App\Support\Permisos;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Cotización de renta como IMAGEN PNG — se sirve como DESCARGA (no
 * inline): el flujo es guardarla y adjuntarla en el WhatsApp del
 * cliente. Mismo permiso que el PDF de composición; 404 si el proyecto
 * no es renta.
 */
final class CotizacionRentaImagenController
{
    public function __invoke(Proyecto $proyecto, CotizacionRentaService $cotizacion): BinaryFileResponse
    {
        abort_unless(auth()->user()?->can(Permisos::DESCARGAR_PDF_COMPOSICION_PROYECTO) ?? false, 403);
        abort_unless($proyecto->esRenta(), 404);

        try {
            $ruta = $cotizacion->generarImagen($proyecto);
        } catch (Throwable $e) {
            report($e);

            abort(503, 'No se pudo generar la imagen. El detalle quedó registrado en el log.');
        }

        return response()->download($ruta, "cotizacion-{$proyecto->codigo}.png");
    }
}
