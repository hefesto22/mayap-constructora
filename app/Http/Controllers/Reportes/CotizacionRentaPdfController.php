<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Models\Proyecto;
use App\Services\Reportes\CotizacionRentaService;
use App\Support\Permisos;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Vista previa de la cotización de renta (PDF, inline) — reusa el
 * permiso del PDF de composición: ambos son "lo pactado que ve el
 * cliente". Solo existe para proyectos tipo renta (404 en el resto).
 */
final class CotizacionRentaPdfController
{
    use MuestraPdfInline;

    public function __invoke(Proyecto $proyecto, CotizacionRentaService $cotizacion): BinaryFileResponse
    {
        abort_unless(auth()->user()?->can(Permisos::DESCARGAR_PDF_COMPOSICION_PROYECTO) ?? false, 403);
        abort_unless($proyecto->esRenta(), 404);

        return $this->pdfInline(
            fn (): string => $cotizacion->generarPdf($proyecto),
            "cotizacion-{$proyecto->codigo}.pdf",
        );
    }
}
