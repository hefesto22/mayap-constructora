<?php

declare(strict_types=1);

namespace App\Filament\Resources\AsignacionesMaquina\Pages;

use App\Filament\Resources\AsignacionesMaquina\Actions\AccionAsignar;
use App\Filament\Resources\AsignacionesMaquina\AsignacionMaquinaResource;
use Filament\Resources\Pages\ListRecords;

class ListAsignacionesMaquina extends ListRecords
{
    protected static string $resource = AsignacionMaquinaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AccionAsignar::make(),
        ];
    }
}
