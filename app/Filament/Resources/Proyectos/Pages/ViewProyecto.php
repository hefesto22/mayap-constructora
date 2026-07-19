<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Pages;

use App\Filament\Resources\Proyectos\Actions\AccionCobrarProyecto;
use App\Filament\Resources\Proyectos\Actions\AccionesRenta;
use App\Filament\Resources\Proyectos\ProyectoResource;
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
            $this->accionVerPdf(
                nombre: 'pdf_composicion',
                etiqueta: 'PDF Composición',
                permiso: Permisos::DESCARGAR_PDF_COMPOSICION_PROYECTO,
                ruta: 'reportes.composicion-proyecto',
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
