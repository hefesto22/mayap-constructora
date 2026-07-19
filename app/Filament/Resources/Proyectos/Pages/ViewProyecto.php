<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Pages;

use App\Filament\Resources\Proyectos\Actions\AccionCobrarProyecto;
use App\Filament\Resources\Proyectos\Actions\AccionesRenta;
use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Models\Proyecto;
use App\Services\Reportes\CotizacionRentaService;
use App\Support\Permisos;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProyecto extends ViewRecord
{
    protected static string $resource = ProyectoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Presupuesto completo pactado (renglones, precios, ISV,
            // condiciones, firmas) — respaldo contractual y expediente.
            // Solo presupuestados: la renta tiene su propia cotización.
            $this->accionVerPdf(
                nombre: 'pdf_composicion',
                etiqueta: 'PDF Composición',
                permiso: Permisos::DESCARGAR_PDF_COMPOSICION_PROYECTO,
                ruta: 'reportes.composicion-proyecto',
            )->visible(fn (): bool => ! $this->proyecto()->esRenta()
                && (auth()->user()?->can(Permisos::DESCARGAR_PDF_COMPOSICION_PROYECTO) ?? false)),

            // ── Cotización de renta (decisión Mauricio 2026-07-19) ─────
            // La IMAGEN es la protagonista: se manda por WhatsApp y el
            // cliente la ve al instante. El PDF queda para lo formal.
            // Mismo permiso que la composición: ambos son "lo pactado".
            Action::make('cotizacion_imagen')
                ->label('Imagen para WhatsApp')
                ->icon('heroicon-o-photo')
                ->color('success')
                ->visible(fn (): bool => $this->proyecto()->esRenta()
                    && (auth()->user()?->can(Permisos::DESCARGAR_PDF_COMPOSICION_PROYECTO) ?? false))
                ->url(fn (): string => route('reportes.cotizacion-renta-imagen', $this->getRecord())),

            $this->accionVerPdf(
                nombre: 'cotizacion_pdf',
                etiqueta: 'Cotización PDF',
                permiso: Permisos::DESCARGAR_PDF_COMPOSICION_PROYECTO,
                ruta: 'reportes.cotizacion-renta',
            )->visible(fn (): bool => $this->proyecto()->esRenta()
                && (auth()->user()?->can(Permisos::DESCARGAR_PDF_COMPOSICION_PROYECTO) ?? false)),

            // Abre el chat del cliente con mensaje prellenado: se adjunta
            // la imagen descargada y se envía (WhatsApp no permite
            // adjuntar automático).
            Action::make('whatsapp_cliente')
                ->label('WhatsApp del cliente')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('success')
                ->visible(fn (): bool => $this->proyecto()->esRenta()
                    && app(CotizacionRentaService::class)->linkWhatsApp($this->proyecto()) !== null
                    && (auth()->user()?->can(Permisos::DESCARGAR_PDF_COMPOSICION_PROYECTO) ?? false))
                ->url(
                    fn (): string => (string) app(CotizacionRentaService::class)->linkWhatsApp($this->proyecto()),
                    shouldOpenInNewTab: true,
                ),

            // Reporte gerencial: costo real y MARGEN — dato sensible.
            $this->accionVerPdf(
                nombre: 'pdf_costos',
                etiqueta: 'PDF Costos',
                permiso: Permisos::DESCARGAR_PDF_COSTOS_PROYECTO,
                ruta: 'reportes.costo-obra',
            ),

            // Renta de maquinaria: aprobar (agenda + CxC) y extender.
            AccionesRenta::aprobar(),
            AccionesRenta::extender(),
            AccionCobrarProyecto::make(),

            EditAction::make(),
        ];
    }

    private function proyecto(): Proyecto
    {
        /** @var Proyecto $proyecto */
        $proyecto = $this->getRecord();

        return $proyecto;
    }

    /**
     * Acción de vista previa de PDF: abre el documento INLINE en una
     * pestaña nueva (visor del navegador — imprimir/descargar son opción
     * del usuario, no obligación). Visible solo con su permiso
     * personalizado; el controller lo re-valida en servidor.
     */
    private function accionVerPdf(string $nombre, string $etiqueta, string $permiso, string $ruta): Action
    {
        return Action::make($nombre)
            ->label($etiqueta)
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can($permiso) ?? false)
            ->url(fn (): string => route($ruta, $this->getRecord()), shouldOpenInNewTab: true);
    }
}
