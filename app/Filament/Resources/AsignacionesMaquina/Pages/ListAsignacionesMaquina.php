<?php

declare(strict_types=1);

namespace App\Filament\Resources\AsignacionesMaquina\Pages;

use App\Filament\Resources\AsignacionesMaquina\Actions\AccionAsignar;
use App\Filament\Resources\AsignacionesMaquina\AsignacionMaquinaResource;
use Filament\Resources\Pages\ListRecords;
use Override;

class ListAsignacionesMaquina extends ListRecords
{
    protected static string $resource = AsignacionMaquinaResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            AccionAsignar::make(),
        ];
    }
}
