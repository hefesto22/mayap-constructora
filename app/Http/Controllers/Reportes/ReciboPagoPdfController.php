<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Enums\EstadoPlanilla;
use App\Models\Planilla;
use App\Services\Planilla\ReciboPagoService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Recibos de pago de una planilla (PDF inline, uno por página) — solo
 * planillas CERRADAS: el recibo es del pago confirmado, no del borrador.
 * Permiso del módulo de planillas re-validado en servidor.
 */
final class ReciboPagoPdfController
{
    use MuestraPdfInline;

    public function __invoke(Planilla $planilla, ReciboPagoService $recibos): BinaryFileResponse
    {
        abort_unless(auth()->user()?->can('View:Planilla') ?? false, 403);
        abort_unless($planilla->estado === EstadoPlanilla::Cerrada, 404);

        return $this->pdfInline(
            fn (): string => $recibos->generar($planilla),
            "recibos-{$planilla->codigo}.pdf",
        );
    }
}
