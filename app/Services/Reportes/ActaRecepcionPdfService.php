<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\User;
use App\Services\Compras\AlcanceDestinoCompra;
use App\Support\Roles;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;

/**
 * PDF "Acta de recepción" de una compra verificada (G2): qué decía la
 * factura, qué se recibió realmente en cada destino, la DIFERENCIA
 * (reclamo al proveedor), y quién verificó cada línea y cuándo.
 *
 * ALCANCE: quien gestiona la compra (recepción, gerencia, admin) recibe el
 * acta COMPLETA; el bodeguero/encargado solo SU porción — acta PARCIAL sin
 * el total facturado (cada quien ve únicamente lo que le corresponde).
 *
 * Es el documento físico del expediente de compras y el soporte del
 * reclamo: se imprime, se firma y se adjunta a la factura del proveedor.
 */
final class ActaRecepcionPdfService
{
    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly AlcanceDestinoCompra $alcance,
    ) {}

    /**
     * Líneas del acta que este usuario puede ver. Sin usuario (tests,
     * procesos internos) → todas.
     *
     * @return Collection<int, CompraLinea>
     */
    public function lineasVisibles(Compra $compra, ?User $paraUsuario = null): Collection
    {
        $compra->loadMissing('lineas');

        if ($paraUsuario === null || Roles::compra($paraUsuario)) {
            return $compra->lineas->values();
        }

        return $compra->lineas
            ->filter(fn (CompraLinea $l): bool => $this->alcance->alcanza($paraUsuario, $compra, $l))
            ->values();
    }

    /**
     * Renderiza el HTML del acta (sin generar el PDF). Testeable.
     */
    public function construirHtml(Compra $compra, ?User $paraUsuario = null): string
    {
        $compra->loadMissing([
            'proveedor:id,codigo,nombre',
            'bodega:id,codigo,nombre',
            'proyecto:id,codigo,nombre',
            'lineas.material:id,codigo,nombre',
            'lineas.bodega:id,nombre',
            'lineas.proyecto:id,nombre',
            'lineas.verificadaPor:id,name',
        ]);

        $lineas = $this->lineasVisibles($compra, $paraUsuario);

        return View::make('pdf.acta-recepcion', [
            'compra' => $compra,
            'lineas' => $lineas,
            // Parcial = el lector NO tiene la compra completa a su alcance:
            // el acta lo declara y omite el total facturado del documento.
            'parcial' => $lineas->count() !== $compra->lineas->count(),
        ])->render();
    }

    /**
     * Genera el PDF y lo guarda en storage. Devuelve la ruta absoluta.
     * El nombre incluye el alcance para no pisar el acta completa con una
     * parcial (ni entre dos usuarios concurrentes).
     */
    public function generar(Compra $compra, ?User $paraUsuario = null): string
    {
        $sufijo = $paraUsuario === null || Roles::compra($paraUsuario)
            ? 'completa'
            : "u{$paraUsuario->id}";

        return $this->pdf->guardar(
            $this->construirHtml($compra, $paraUsuario),
            storage_path("app/reportes/acta-recepcion/acta-{$compra->codigo}-{$sufijo}.pdf"),
        );
    }
}
