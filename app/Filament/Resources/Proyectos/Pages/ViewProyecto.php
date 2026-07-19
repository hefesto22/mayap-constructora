<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Pages;

use App\Filament\Resources\Proyectos\Actions\AccionCobrarProyecto;
use App\Filament\Resources\Proyectos\Actions\AccionEnviarCotizacionWhatsApp;
use App\Filament\Resources\Proyectos\Actions\AccionesRenta;
use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Models\Proyecto;
use App\Services\Reportes\CotizacionRentaService;
use App\Support\Permisos;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * Cabecera SIN ruido (decisión Mauricio 2026-07-19): UN botón principal
 * (el envío directo por WhatsApp, el caso del día a día) y las demás
 * salidas de la cotización agrupadas en el menú "Cotización" — nada de
 * cuatro botones sueltos para la misma cosa.
 */
class ViewProyecto extends ViewRecord
{
    protected static string $resource = ProyectoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // ── La acción estrella de las rentas: se la mando al cliente ──
            AccionEnviarCotizacionWhatsApp::make(),

            // ── Las demás salidas de la cotización, agrupadas ─────────────
            ActionGroup::make([
                $this->accionVerPdf(
                    nombre: 'cotizacion_pdf',
                    etiqueta: 'Ver PDF',
                    permiso: Permisos::DESCARGAR_PDF_COMPOSICION_PROYECTO,
                    ruta: 'reportes.cotizacion-renta',
                ),

                Action::make('cotizacion_imagen')
                    ->label('Descargar imagen (WhatsApp)')
                    ->icon('heroicon-o-photo')
                    ->visible(fn (): bool => auth()->user()?->can(Permisos::DESCARGAR_PDF_COMPOSICION_PROYECTO) ?? false)
                    ->url(fn (): string => route('reportes.cotizacion-renta-imagen', $this->getRecord())),

                // Respaldo manual: abre el chat con mensaje prellenado y se
                // adjunta la imagen descargada (WhatsApp no permite adjuntar
                // automático por enlace).
                Action::make('whatsapp_cliente')
                    ->label('Abrir chat del cliente')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->visible(fn (): bool => app(CotizacionRentaService::class)->linkWhatsApp($this->proyecto()) !== null
                        && (auth()->user()?->can(Permisos::DESCARGAR_PDF_COMPOSICION_PROYECTO) ?? false))
                    ->url(
                        fn (): string => (string) app(CotizacionRentaService::class)->linkWhatsApp($this->proyecto()),
                        shouldOpenInNewTab: true,
                    ),
            ])
                ->label('Cotización')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->button()
                ->visible(fn (): bool => $this->proyecto()->esRenta()
                    && (auth()->user()?->can(Permisos::DESCARGAR_PDF_COMPOSICION_PROYECTO) ?? false)),

            // Presupuesto completo pactado — SOLO presupuestados: la renta
            // tiene su propia cotización.
            $this->accionVerPdf(
                nombre: 'pdf_composicion',
                etiqueta: 'PDF Composición',
                permiso: Permisos::DESCARGAR_PDF_COMPOSICION_PROYECTO,
                ruta: 'reportes.composicion-proyecto',
            )->visible(fn (): bool => ! $this->proyecto()->esRenta()
                && (auth()->user()?->can(Permisos::DESCARGAR_PDF_COMPOSICION_PROYECTO) ?? false)),

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
