<?php

declare(strict_types=1);

namespace App\Filament\Resources\Mantenimientos\Pages;

use App\Filament\Resources\Mantenimientos\Actions\AccionCambiarPrioridad;
use App\Filament\Resources\Mantenimientos\Actions\AccionFinalizarMantenimiento;
use App\Filament\Resources\Mantenimientos\Actions\AccionRegistrarAvance;
use App\Filament\Resources\Mantenimientos\MantenimientoMaquinaResource;
use Filament\Resources\Pages\ViewRecord;
use Override;

class ViewMantenimiento extends ViewRecord
{
    protected static string $resource = MantenimientoMaquinaResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            AccionRegistrarAvance::make(),
            AccionCambiarPrioridad::make(),
            AccionFinalizarMantenimiento::make(),
        ];
    }
}
