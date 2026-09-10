<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Pages;

use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Models\Proyecto;
use App\Services\Proyectos\CalcularPrecioProyectoService;
use Filament\Resources\Pages\CreateRecord;
use Override;

class CreateProyecto extends CreateRecord
{
    protected static string $resource = ProyectoResource::class;

    /**
     * Después de crear redirige al edit para que el usuario empiece
     * a agregar renglones inmediatamente. Sin este override, Filament
     * por default va al listado, lo cual fuerza un click extra.
     */
    /**
     * Obra heredada: el presupuesto viene del monto contratado, no de los
     * renglones, así que hay que fijar los totales ya mismo. Sin esto la
     * obra queda con subtotal 0 hasta el primer guardado del edit y el
     * margen sale al 100%.
     */
    protected function afterCreate(): void
    {
        $proyecto = $this->getRecord();

        if ($proyecto instanceof Proyecto && $proyecto->usaMontoContratado()) {
            app(CalcularPrecioProyectoService::class)->recalcular($proyecto);
        }
    }

    #[Override]
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
