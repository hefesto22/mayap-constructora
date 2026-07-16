<?php

declare(strict_types=1);

namespace App\Filament\Resources\SolicitudesMaquina\Pages;

use App\Filament\Resources\SolicitudesMaquina\SolicitudMaquinaResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Vista de UNA solicitud — a donde aterriza la campanita. Si está
 * pendiente, aquí mismo se resuelve (Agendar / Rechazar — mismas
 * acciones de la tabla, única definición).
 */
class ViewSolicitudMaquina extends ViewRecord
{
    protected static string $resource = SolicitudMaquinaResource::class;

    protected function getHeaderActions(): array
    {
        return SolicitudMaquinaResource::accionesResolver();
    }
}
