<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Models\Proyecto;
use App\Services\Reportes\CostoObraPdfService;
use App\Support\Permisos;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Vista previa del reporte de costos y margen de una obra — dato sensible:
 * exige el permiso personalizado (pestaña Personalizados de Roles) también
 * en el servidor, no solo en la visibilidad del botón.
 */
final class CostoObraPdfController
{
    use MuestraPdfInline;

    public function __invoke(Proyecto $proyecto, CostoObraPdfService $costos): BinaryFileResponse
    {
        abort_unless(auth()->user()?->can(Permisos::DESCARGAR_PDF_COSTOS_PROYECTO) ?? false, 403);

        return $this->pdfInline(
            fn (): string => $costos->generar($proyecto),
            "costo-{$proyecto->codigo}.pdf",
        );
    }
}
