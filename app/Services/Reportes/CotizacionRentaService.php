<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use App\Enums\CondicionPago;
use App\Models\Proyecto;
use Illuminate\Support\Facades\View;

/**
 * Cotización de renta de maquinaria — el documento QUE VE EL CLIENTE:
 * máquinas con cantidad/tarifa/llegada, totales con ISV, condición de
 * pago y vigencia. Sale en DOS formatos con el mismo diseño (decisión
 * Mauricio 2026-07-19):
 *
 *  - IMAGEN PNG: para mandarla por WhatsApp — se ve al instante en el
 *    chat, sin abrir archivos.
 *  - PDF: para el cliente formal o para imprimir.
 *
 * Usa los SNAPSHOTS pactados de las líneas (tarifa_snapshot), nunca el
 * catálogo vivo. El armado del HTML está separado para probarse sin
 * Chromium.
 */
final class CotizacionRentaService
{
    public function __construct(private readonly PdfRenderer $pdf) {}

    /**
     * Renderiza el HTML de la cotización (sin generar nada). Testeable.
     */
    public function construirHtml(Proyecto $proyecto): string
    {
        $proyecto->loadMissing([
            'cliente:id,codigo,nombre,rtn,telefono,condicion_pago,dias_credito',
            'lineasRenta' => fn ($q) => $q->orderBy('orden'),
            'lineasRenta.maquina:id,codigo,nombre,tipo,marca,modelo',
        ]);

        return View::make('pdf.cotizacion-renta', [
            'proyecto' => $proyecto,
        ])->render();
    }

    public function generarPdf(Proyecto $proyecto): string
    {
        return $this->pdf->guardar(
            $this->construirHtml($proyecto),
            storage_path("app/reportes/cotizacion-renta/cotizacion-{$proyecto->codigo}.pdf"),
        );
    }

    public function generarImagen(Proyecto $proyecto): string
    {
        return $this->pdf->imagen(
            $this->construirHtml($proyecto),
            storage_path("app/reportes/cotizacion-renta/cotizacion-{$proyecto->codigo}.png"),
        );
    }

    /**
     * Enlace wa.me al chat del cliente con mensaje prellenado — el
     * usuario adjunta la imagen descargada y envía (WhatsApp no permite
     * adjuntar automático). Null si el cliente no tiene teléfono.
     *
     * Teléfono: se limpia a dígitos; 8 dígitos = número hondureño → se
     * antepone 504.
     */
    public function linkWhatsApp(Proyecto $proyecto): ?string
    {
        $proyecto->loadMissing('cliente:id,nombre,telefono,condicion_pago,dias_credito');

        $cliente = $proyecto->cliente;

        if ($cliente === null) {
            return null;
        }

        $telefono = preg_replace('/\D+/', '', (string) $cliente->telefono) ?? '';

        if ($telefono === '') {
            return null;
        }

        if (strlen($telefono) === 8) {
            $telefono = '504'.$telefono;
        }

        $mensaje = "Buen día, le comparto la cotización {$proyecto->codigo} de renta de maquinaria"
            .' por L '.number_format((float) $proyecto->total_cache, 2)
            .' (ISV incluido). Quedamos atentos a su confirmación.';

        return 'https://wa.me/'.$telefono.'?text='.rawurlencode($mensaje);
    }

    /**
     * Texto de la condición de pago que ve el cliente.
     */
    public static function condicionPagoLabel(Proyecto $proyecto): string
    {
        $cliente = $proyecto->cliente;

        if ($cliente === null) {
            return 'Por definir';
        }

        return $cliente->condicion_pago === CondicionPago::Credito
            ? "Crédito a {$cliente->dias_credito} días"
            : 'Contado';
    }
}
