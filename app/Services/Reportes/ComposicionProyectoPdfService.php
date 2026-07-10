<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use App\Models\Proyecto;
use Illuminate\Support\Facades\View;

/**
 * PDF "Composición del proyecto" — el presupuesto COMPLETO tal como se
 * pactó: identificación, renglones agrupados por capítulo (ficha, unidad,
 * cantidad, precio unitario snapshot, subtotal), totales con ISV,
 * condiciones (anticipo/plazo) y firmas.
 *
 * Es el respaldo contractual y el documento del expediente físico de la
 * obra. Usa los SNAPSHOTS de precio de los renglones (lo pactado), nunca
 * el precio vivo de las fichas.
 *
 * El armado del HTML está separado de la generación del PDF para poder
 * probar el contenido sin depender de Chromium en el entorno de tests.
 */
final class ComposicionProyectoPdfService
{
    public function __construct(
        private readonly PdfRenderer $pdf,
    ) {}

    /**
     * Renderiza el HTML del documento (sin generar el PDF). Testeable.
     */
    public function construirHtml(Proyecto $proyecto): string
    {
        $proyecto->loadMissing([
            'cliente:id,codigo,nombre,rtn',
            'zona:id,codigo,nombre',
            'renglones' => fn ($q) => $q->orderBy('orden'),
            'renglones.ficha:id,codigo,nombre,unidad_medida_id',
            'renglones.ficha.unidadMedida:id,codigo,nombre',
            // Desglose de insumos por ficha (SIN precios — decisión Mauricio
            // 2026-07-10: el cliente ve qué lleva cada partida, nunca los
            // costos internos ni la utilidad).
            'renglones.ficha.lineas' => fn ($q) => $q->orderBy('orden'),
            'renglones.ficha.lineas.item:id,nombre,categoria,unidad_medida_id',
            'renglones.ficha.lineas.item.unidadMedida:id,codigo',
        ]);

        // Agrupar por capítulo CONSERVANDO el orden de los renglones.
        // Los sin capítulo van bajo la clave '' (se dibujan sin encabezado).
        $capitulos = $proyecto->renglones->groupBy(
            fn ($renglon): string => (string) ($renglon->capitulo ?? ''),
        );

        return View::make('pdf.composicion-proyecto', [
            'proyecto'  => $proyecto,
            'capitulos' => $capitulos,
        ])->render();
    }

    /**
     * Genera el PDF y lo guarda en storage. Devuelve la ruta absoluta.
     */
    public function generar(Proyecto $proyecto): string
    {
        return $this->pdf->guardar(
            $this->construirHtml($proyecto),
            storage_path("app/reportes/composicion-proyecto/composicion-{$proyecto->codigo}.pdf"),
        );
    }
}
