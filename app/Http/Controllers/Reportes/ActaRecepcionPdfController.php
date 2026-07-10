<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Enums\EstadoCompra;
use App\Models\Compra;
use App\Services\Reportes\ActaRecepcionPdfService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Vista previa del acta de recepción de una compra (G2).
 *
 * Misma regla de negocio que el botón de la tabla: el acta existe cuando ya
 * hay verificación (compra confirmada, o parcial en curso). El permiso se
 * valida AQUÍ además del botón — defense in depth: la URL directa sin
 * permiso responde 403.
 */
final class ActaRecepcionPdfController
{
    use MuestraPdfInline;

    public function __invoke(Compra $compra, ActaRecepcionPdfService $acta): BinaryFileResponse
    {
        $user = auth()->user();

        abort_unless($user?->can('View:Compra') ?? false, 403);

        $hayVerificacion = $compra->estado === EstadoCompra::Confirmada
            || ($compra->estado === EstadoCompra::PorRecibir
                && $compra->lineas()->whereNotNull('verificada_at')->exists());

        abort_unless($hayVerificacion, 404, 'La compra aún no tiene recepción verificada.');

        // Alcance: recepción/gerencia/admin → acta completa; bodeguero y
        // encargado → SOLO su porción. Sin porción alguna → nada que ver.
        abort_if(
            $acta->lineasVisibles($compra, $user)->isEmpty(),
            403,
            'Ningún destino de esta compra está a tu cargo.',
        );

        return $this->pdfInline(
            fn (): string => $acta->generar($compra, $user),
            "acta-{$compra->codigo}.pdf",
        );
    }
}
