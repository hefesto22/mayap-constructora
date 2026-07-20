<?php

declare(strict_types=1);

namespace App\Services\Planilla;

use App\Models\Planilla;
use App\Models\PlanillaLinea;
use App\Services\Reportes\PdfRenderer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;

/**
 * Recibos de pago de una planilla CERRADA — el papel que firma cada
 * empleado al recibir su pago (pedido del cliente 2026-07-20).
 *
 * Un solo PDF trae TODOS los recibos del período (uno por página, listos
 * para imprimir de un solo y recortar/firmar); con $soloLineaId sale el
 * recibo individual de un empleado.
 *
 * Muestra bruto − retención (12.5% honorarios) − deducciones = NETO.
 * Diseño estándar de la casa — se ajustará al formato que el cliente
 * pase, sin tocar la tubería.
 */
final class ReciboPagoService
{
    public function __construct(private readonly PdfRenderer $pdf) {}

    /**
     * Renderiza el HTML de los recibos (sin generar el PDF). Testeable.
     */
    public function construirHtml(Planilla $planilla, ?int $soloLineaId = null): string
    {
        $planilla->loadMissing([
            'lineas.empleado:id,codigo,nombre,identidad,cargo',
            'lineas.proyecto:id,codigo,nombre',
        ]);

        /** @var Collection<int, PlanillaLinea> $lineas */
        $lineas = $planilla->lineas
            ->when($soloLineaId !== null, fn (Collection $c): Collection => $c->where('id', $soloLineaId))
            ->sortBy(fn (PlanillaLinea $l): string => $l->empleado->nombre)
            ->values();

        return View::make('pdf.recibos-planilla', [
            'planilla' => $planilla,
            'lineas'   => $lineas,
        ])->render();
    }

    public function generar(Planilla $planilla, ?int $soloLineaId = null): string
    {
        $sufijo = $soloLineaId !== null ? "-linea-{$soloLineaId}" : '';

        return $this->pdf->guardar(
            $this->construirHtml($planilla, $soloLineaId),
            storage_path("app/reportes/recibos/recibos-{$planilla->codigo}{$sufijo}.pdf"),
        );
    }
}
