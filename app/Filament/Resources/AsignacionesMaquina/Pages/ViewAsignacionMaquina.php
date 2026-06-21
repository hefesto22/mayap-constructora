<?php

declare(strict_types=1);

namespace App\Filament\Resources\AsignacionesMaquina\Pages;

use App\Filament\Resources\AsignacionesMaquina\Actions\AccionFinalizar;
use App\Filament\Resources\AsignacionesMaquina\Actions\AccionRegistrarCombustible;
use App\Filament\Resources\AsignacionesMaquina\Actions\AccionRegistrarParte;
use App\Filament\Resources\AsignacionesMaquina\AsignacionMaquinaResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAsignacionMaquina extends ViewRecord
{
    protected static string $resource = AsignacionMaquinaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AccionRegistrarParte::make(),
            AccionRegistrarCombustible::make(),
            AccionFinalizar::make(),
        ];
    }
}
