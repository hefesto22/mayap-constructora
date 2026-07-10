<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Models\Proyecto;
use App\Services\Reportes\ComposicionProyectoPdfService;
use App\Support\Permisos;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Vista previa de la composición del proyecto (expediente contractual con
 * precios pactados) — protegida por su permiso personalizado en servidor.
 */
final class ComposicionProyectoPdfController
{
    use MuestraPdfInline;

    public function __invoke(Proyecto $proyecto, ComposicionProyectoPdfService $composicion): BinaryFileResponse
    {
        abort_unless(auth()->user()?->can(Permisos::DESCARGAR_PDF_COMPOSICION_PROYECTO) ?? false, 403);

        return $this->pdfInline(
            fn (): string => $composicion->generar($proyecto),
            "composicion-{$proyecto->codigo}.pdf",
        );
    }
}
