<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Exports\ResumenRentaExport;
use App\Models\Proyecto;
use App\Services\Reportes\ResumenRentaService;
use App\Support\Permisos;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Descarga del Excel "pactado vs real" de una renta — dato gerencial
 * (montos y comparativa): exige el mismo permiso personalizado que el
 * PDF de costos, también en servidor. Solo proyectos de renta (404).
 */
final class ResumenRentaExcelController
{
    public function __invoke(Proyecto $proyecto, ResumenRentaService $servicio): BinaryFileResponse
    {
        abort_unless(auth()->user()?->can(Permisos::DESCARGAR_PDF_COSTOS_PROYECTO) ?? false, 403);
        abort_unless($proyecto->esRenta(), 404);

        return Excel::download(
            new ResumenRentaExport($proyecto, $servicio),
            "resumen-renta-{$proyecto->codigo}.xlsx",
        );
    }
}
